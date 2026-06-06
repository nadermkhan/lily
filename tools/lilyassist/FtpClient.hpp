#pragma once
#include <string>
#include <vector>
#include <array>
#include <iostream>
#include <sstream>
#include <memory>
#include <stdexcept>
#include <stdio.h>
#include <mutex>
#include <thread>
#include <chrono>

inline std::mutex g_printMutex;

struct RemoteFile {
    std::string name;
    bool isDir;
    size_t size;
};

class FtpClient {
    std::string host;
    std::string user;
    std::string pass;
    int port;
    bool secure;
    std::string root;

public:
    std::string execCommand(const std::string& cmd, bool& success) {
        std::array<char, 128> buffer;
        std::string result;
        success = false;
        FILE* pipe = _popen((cmd + " 2>&1").c_str(), "r");
        if (!pipe) {
            return "popen failed!";
        }
        while (fgets(buffer.data(), buffer.size(), pipe) != nullptr) {
            result += buffer.data();
        }
        int returnCode = _pclose(pipe);
        success = (returnCode == 0);
        return result;
    }

    FtpClient(const std::string& h, int p, const std::string& u, const std::string& pwd, const std::string& r, bool sec) 
        : host(h), port(p), user(u), pass(pwd), root(r), secure(sec) {
        if (!root.empty() && root.back() != '/') {
            root += "/";
        }
        if (root.empty()) {
            root = "/";
        }
    }

    std::string getBaseUrl() const {
        std::string scheme = (secure && port == 990) ? "ftps://" : "ftp://";
        return scheme + host + ":" + std::to_string(port) + root;
    }

    std::string getAuthArg() const {
        return " --user \"" + user + ":" + pass + "\" ";
    }
    
    std::string getSecureArg() const {
        return secure ? " --ssl-reqd --insecure " : " ";
    }

    bool uploadBatch(const std::vector<std::string>& localPaths) {
        if (localPaths.empty()) return true;

        std::string cmd = "curl.exe -sS --ftp-create-dirs ";
        for (const auto& path : localPaths) {
            std::string url = getBaseUrl() + path;
            cmd += "-T \"" + path + "\" \"" + url + "\" ";
        }
        cmd += getAuthArg() + getSecureArg();

        int retries = 3;
        while (retries > 0) {
            bool success;
            std::string output = execCommand(cmd, success);
            if (success) return true;
            
            retries--;
            if (retries == 0) {
                std::lock_guard<std::mutex> lock(g_printMutex);
                std::cerr << "Batch upload failed: " << output << "\n";
                return false;
            }
            std::this_thread::sleep_for(std::chrono::milliseconds(2000));
        }
        return false;
    }

    bool downloadBatch(const std::vector<std::pair<std::string, std::string>>& paths) {
        if (paths.empty()) return true;

        std::string cmd = "curl.exe -sS ";
        for (const auto& p : paths) {
            std::string url = getBaseUrl() + p.first;
            cmd += "-o \"" + p.second + "\" \"" + url + "\" ";
        }
        cmd += getAuthArg() + getSecureArg();

        int retries = 3;
        while (retries > 0) {
            bool success;
            std::string output = execCommand(cmd, success);
            if (success) return true;
            
            retries--;
            if (retries == 0) {
                std::lock_guard<std::mutex> lock(g_printMutex);
                std::cerr << "Batch download failed: " << output << "\n";
                return false;
            }
            std::this_thread::sleep_for(std::chrono::milliseconds(2000));
        }
        return false;
    }

    bool upload(const std::string& local, const std::string& remote) {
        std::string cmd = "curl.exe -sS --ftp-create-dirs -T \"" + local + "\" \"" + getBaseUrl() + remote + "\" " + getAuthArg() + getSecureArg();
        bool success;
        std::string output = execCommand(cmd, success);
        if (!success) std::cerr << "Upload failed: " << output << "\n";
        return success;
    }

    bool download(const std::string& remote, const std::string& local) {
        std::string cmd = "curl.exe -sS -o \"" + local + "\" \"" + getBaseUrl() + remote + "\" " + getAuthArg() + getSecureArg();
        bool success;
        std::string output = execCommand(cmd, success);
        if (!success) std::cerr << "Download failed: " << output << "\n";
        return success;
    }

    bool deleteFile(const std::string& remotePath) {
        std::string url = getBaseUrl();
        std::string cmd = "curl.exe -sS -Q \"-DELE " + root + remotePath + "\" \"" + url + "\"" + getAuthArg() + getSecureArg();
        bool success;
        execCommand(cmd, success);
        return success;
    }

    std::vector<RemoteFile> listFiles(const std::string& remotePath) {
        std::string url = getBaseUrl() + remotePath;
        if (url.back() != '/') url += "/";
        std::string cmd = "curl.exe -sS \"" + url + "\"" + getAuthArg() + getSecureArg();
        bool success;
        std::string output = execCommand(cmd, success);
        std::vector<RemoteFile> files;
        if (success) {
            std::stringstream ss(output);
            std::string line;
            while(std::getline(ss, line)) {
                if (!line.empty() && line.back() == '\r') line.pop_back();
                if (line.empty()) continue;
                bool isDir = (line[0] == 'd');
                std::vector<std::string> parts;
                std::stringstream lineSs(line);
                std::string part;
                while (lineSs >> part) {
                    parts.push_back(part);
                    if (parts.size() == 8) break; 
                }
                if (parts.size() == 8) {
                    std::string name;
                    std::getline(lineSs, name);
                    if (!name.empty() && name[0] == ' ') name = name.substr(1);
                    if (name == "." || name == "..") continue;
                    size_t size = 0;
                    try { size = std::stoull(parts[4]); } catch(...) {}
                    files.push_back({name, isDir, size});
                }
            }
        }
        return files;
    }
};
