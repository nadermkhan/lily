#include <iostream>
#include <string>
#include <vector>
#include <filesystem>
#include <algorithm>
#include <atomic>
#include "Config.hpp"
#include "Hash.hpp"
#include "State.hpp"
#include "FtpClient.hpp"
#include "ThreadPool.hpp"

namespace fs = std::filesystem;

const std::vector<std::string> ignoredPaths = {
    ".git", "tests", ".ncache", ".env", ".gitignore", "phpunit.xml", "qa_tester.ps1", ".lily_sync_state.json", "tools"
};

bool isIgnored(const std::string& path) {
    std::string normalized = path;
    std::replace(normalized.begin(), normalized.end(), '\\', '/');
    if (normalized.starts_with("./")) normalized = normalized.substr(2);
    
    for (const auto& ignored : ignoredPaths) {
        if (normalized == ignored || normalized.starts_with(ignored + "/")) {
            return true;
        }
    }
    return false;
}

std::vector<std::string> scanLocalDirectory(const std::string& dir) {
    std::vector<std::string> files;
    for (const auto& entry : fs::recursive_directory_iterator(dir)) {
        if (entry.is_regular_file()) {
            std::string path = entry.path().string();
            std::replace(path.begin(), path.end(), '\\', '/');
            if (path.starts_with("./")) path = path.substr(2);
            if (!isIgnored(path)) {
                files.push_back(path);
            }
        }
    }
    return files;
}

void doPush(FtpClient& ftp, State& state, bool force, int concurrency) {
    std::cout << "Scanning local files...\n";
    auto localFiles = scanLocalDirectory(".");
    std::unordered_map<std::string, std::string> previousState = force ? std::unordered_map<std::string, std::string>() : state.getAll();
    std::unordered_map<std::string, std::string> newState;
    
    std::atomic<int> uploadedCount = 0;
    std::vector<std::string> filesToUpload;

    for (const auto& file : localFiles) {
        std::string hash = Hash::sha1_file(file);
        
        {
            std::lock_guard<std::mutex> lock(stateMutex);
            newState[file] = hash;
        }
        
        if (force || previousState.find(file) == previousState.end() || previousState.at(file) != hash) {
            filesToUpload.push_back(file);
        }
    }

    if (!filesToUpload.empty()) {
        ThreadPool pool(concurrency);
        const size_t batchSize = 25;
        for (size_t i = 0; i < filesToUpload.size(); i += batchSize) {
            std::vector<std::string> batch;
            for (size_t j = i; j < i + batchSize && j < filesToUpload.size(); ++j) {
                batch.push_back(filesToUpload[j]);
            }
            
            pool.enqueue([&ftp, batch, &stateMutex, &newState, &uploadedCount]() {
                {
                    std::lock_guard<std::mutex> lock(g_printMutex);
                    for (const auto& f : batch) {
                        std::cout << "Uploading: " << f << "...\n";
                    }
                }
                
                if (ftp.uploadBatch(batch)) {
                    uploadedCount += batch.size();
                } else {
                    std::lock_guard<std::mutex> lock(stateMutex);
                    for (const auto& f : batch) {
                        newState.erase(f); // remove from new state so it will be retried next time
                    }
                }
            });
        }
    }

    // Process deletions
    for (const auto& [file, hash] : previousState) {
        if (newState.find(file) == newState.end() && !fs::exists(file)) {
            std::cout << "Deleting remote: " << file << "...\n";
            ftp.deleteFile(file);
        }
    }
    
    // Merge states if not forced
    if (!force) {
        for (const auto& [file, hash] : previousState) {
            if (newState.find(file) == newState.end() && fs::exists(file)) {
                newState[file] = hash;
            }
        }
    }
    
    // Save to State object
    state.getAll(); // dummy call just to prevent warning if I was going to use it
    
    // clear and set everything new
    auto oldStateCopy = state.getAll();
    for (const auto& [k,v] : oldStateCopy) state.remove(k);
    for (const auto& [file, hash] : newState) state.set(file, hash);
    
    state.save();
    std::cout << "Push complete! " << uploadedCount << " files uploaded.\n";
}

std::atomic<int> downloadedCount = 0;

void collectDownloadFiles(FtpClient& ftp, const std::string& remoteDir, const std::string& localDir, bool force, std::vector<std::pair<std::string, std::string>>& toDownload) {
    if (!fs::exists(localDir) && localDir != ".") {
        fs::create_directories(localDir);
    }
    auto items = ftp.listFiles(remoteDir);
    for (const auto& item : items) {
        std::string localPath = (localDir == "." ? item.name : localDir + "/" + item.name);
        std::string nextRemotePath = (remoteDir == "" || remoteDir == "/") ? item.name : remoteDir + "/" + item.name;
        
        if (isIgnored(localPath)) continue;

        if (item.isDir) {
            collectDownloadFiles(ftp, nextRemotePath, localPath, force, toDownload);
        } else {
            if (!force && fs::exists(localPath)) {
                if (fs::file_size(localPath) == item.size) {
                    continue; // Skip identical size
                }
            }
            toDownload.push_back({nextRemotePath, localPath});
        }
    }
}

void doPull(FtpClient& ftp, State& state, bool force, int concurrency) {
    std::cout << "Fetching remote files...\n";
    downloadedCount = 0;
    
    std::vector<std::pair<std::string, std::string>> filesToDownload;
    collectDownloadFiles(ftp, "", ".", force, filesToDownload);

    if (!filesToDownload.empty()) {
        ThreadPool pool(concurrency);
        const size_t batchSize = 25;
        for (size_t i = 0; i < filesToDownload.size(); i += batchSize) {
            std::vector<std::pair<std::string, std::string>> batch;
            for (size_t j = i; j < i + batchSize && j < filesToDownload.size(); ++j) {
                batch.push_back(filesToDownload[j]);
            }
            
            pool.enqueue([&ftp, batch]() {
                {
                    std::lock_guard<std::mutex> lock(g_printMutex);
                    for (const auto& p : batch) {
                        std::cout << "Downloading: " << p.second << "...\n";
                    }
                }
                
                if (ftp.downloadBatch(batch)) {
                    downloadedCount += batch.size();
                }
            });
        }
    }
    
    // Update local state hashes after pull
    auto localFiles = scanLocalDirectory(".");
    for (const auto& file : localFiles) {
        state.set(file, Hash::sha1_file(file));
    }
    state.save();
    std::cout << "Pull complete! " << downloadedCount << " files downloaded.\n";
}

int main(int argc, char* argv[]) {
    if (argc < 2) {
        std::cout << "Usage: lily-deploy <push|pull> [-f|--force]\n";
        return 1;
    }
    
    std::string command = argv[1];
    bool force = false;
    for (int i = 2; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "-f" || arg == "--force") force = true;
    }
    
    if (command != "push" && command != "pull") {
        std::cout << "Unknown command. Use push or pull.\n";
        return 1;
    }
    
    std::string envPath = "";
    fs::path current = fs::current_path();
    while (true) {
        if (fs::exists(current / ".env")) {
            envPath = (current / ".env").string();
            fs::current_path(current); // Change working directory to project root
            break;
        }
        if (current.has_parent_path() && current != current.parent_path()) {
            current = current.parent_path();
        } else {
            break;
        }
    }

    if (envPath.empty()) {
        std::cout << "Error: Could not find .env file in current or parent directories.\n";
        return 1;
    }
    
    Config env(envPath);
    
    if (env.get("APP_ENV", "development") == "production") {
        std::cout << "Error: " << (command == "push" ? "Push" : "Pull") << " is not allowed in production mode.\n";
        return 1;
    }
    
    std::string host = env.get("FTP_HOST");
    std::string user = env.get("FTP_USER");
    std::string pass = env.get("FTP_PASS");
    std::string portStr = env.get("FTP_PORT", "21");
    int port = 21;
    try { port = std::stoi(portStr); } catch(...) {}
    
    int concurrency = 2;
    std::string convStr = env.get("FTP_CONCURRENCY", "2");
    try { concurrency = std::stoi(convStr); } catch(...) {}
    if (concurrency < 1) concurrency = 1;
    
    std::string root = env.get("FTP_ROOT", "/");
    bool secure = env.getBool("FTP_SECURE", env.getBool("FTP_SSL", true));
    
    if (host.empty() || user.empty() || pass.empty()) {
        std::cout << "Error: FTP credentials missing in .env\n";
        return 1;
    }
    
    FtpClient ftp(host, port, user, pass, root, secure);
    State state(".lily_sync_state.json");
    
    if (command == "push") {
        doPush(ftp, state, force, concurrency);
    } else {
        doPull(ftp, state, force, concurrency);
    }
    
    return 0;
}
