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

void doWipe(FtpClient& ftp, const std::string& appUrl) {
    if (appUrl.empty()) {
        std::cerr << "Error: APP_URL must be set in .env for high-speed remote wipe.\n";
        return;
    }
    std::cout << "Uploading wipe agent...\n";
    std::string agent = "<?php\n"
        "$root = realpath(__DIR__ . '/../');\n"
        "function rrmdir($dir) {\n"
        "    if (is_dir($dir)) {\n"
        "        $objects = scandir($dir);\n"
        "        foreach ($objects as $obj) {\n"
        "            if ($obj != '.' && $obj != '..') {\n"
        "                if (is_dir($dir. DIRECTORY_SEPARATOR .$obj) && !is_link($dir.'/'.$obj))\n"
        "                    rrmdir($dir. DIRECTORY_SEPARATOR .$obj);\n"
        "                else\n"
        "                    @unlink($dir. DIRECTORY_SEPARATOR .$obj);\n"
        "            }\n"
        "        }\n"
        "        @rmdir($dir);\n"
        "    }\n"
        "}\n"
        "foreach(scandir($root) as $item) {\n"
        "    if ($item != '.' && $item != '..') {\n"
        "        $path = $root . '/' . $item;\n"
        "        if (is_dir($path)) rrmdir($path);\n"
        "        else @unlink($path);\n"
        "    }\n"
        "}\n"
        "echo 'SUCCESS';\n";
    
    std::ofstream aout("lily_wipe_agent.php"); aout << agent; aout.close();

    if (ftp.upload("lily_wipe_agent.php", "public/lily_wipe_agent.php")) {
        std::cout << "Triggering instantaneous remote wipe...\n";
        bool succ;
        std::string out = ftp.execCommand("curl.exe -sS \"" + appUrl + "/lily_wipe_agent.php\"", succ);
        if (out.find("SUCCESS") != std::string::npos) {
            std::cout << "Server root wiped completely!\n";
            fs::remove(".lily_sync_state.json");
        } else {
            std::cerr << "Wipe agent failed: " << out << "\n";
        }
    } else {
        std::cerr << "Failed to upload wipe agent.\n";
    }
    fs::remove("lily_wipe_agent.php");
}

void doPush(FtpClient& ftp, State& state, bool force, int concurrency, const std::string& appUrl) {
    std::cout << "Scanning local files...\n";
    auto localFiles = scanLocalDirectory(".");
    std::unordered_map<std::string, std::string> previousState = force ? std::unordered_map<std::string, std::string>() : state.getAll();
    std::unordered_map<std::string, std::string> newState;
    
    std::atomic<int> uploadedCount = 0;
    std::mutex stateMutex;
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
        if (!appUrl.empty()) {
            std::cout << "Archiving " << filesToUpload.size() << " files for Zip Drop Agent...\n";
            std::ofstream listFile(".lily_payload_list.txt");
            for (const auto& f : filesToUpload) {
                listFile << f << "\n";
            }
            listFile.close();

            std::string zipper = "<?php\n"
                "$z = new ZipArchive();\n"
                "$z->open('.lily_payload.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);\n"
                "$files = file('.lily_payload_list.txt', FILE_IGNORE_NEW_LINES);\n"
                "foreach ($files as $f) { $z->addFile($f, $f); }\n"
                "$z->close();\n";
            std::ofstream zout(".lily_zipper.php"); zout << zipper; zout.close();
            system("php .lily_zipper.php");
            fs::remove(".lily_payload_list.txt"); fs::remove(".lily_zipper.php");

            std::cout << "Uploading payload via FTP...\n";
            if (ftp.upload(".lily_payload.zip", ".lily_payload.zip")) {
                std::string agent = "<?php\n"
                    "$root = realpath(__DIR__ . '/../');\n"
                    "$z = new ZipArchive;\n"
                    "if ($z->open($root . '/.lily_payload.zip') === TRUE) {\n"
                    "    $z->extractTo($root . '/'); $z->close();\n"
                    "    unlink($root . '/.lily_payload.zip');\n"
                    "    unlink(__FILE__);\n"
                    "    echo 'SUCCESS';\n"
                    "} else { echo 'FAILED'; }\n";
                std::ofstream aout("lily_agent.php"); aout << agent; aout.close();
                
                std::cout << "Uploading agent script...\n";
                if (ftp.upload("lily_agent.php", "public/lily_agent.php")) {
                    std::cout << "Triggering instantaneous Zip Drop Agent...\n";
                    bool triggerSucc;
                    std::string out = ftp.execCommand("curl.exe -sS \"" + appUrl + "/lily_agent.php\"", triggerSucc);
                    if (out.find("SUCCESS") != std::string::npos) {
                        std::cout << "Zip Drop Deployment Complete! " << filesToUpload.size() << " files extracted on server.\n";
                        for (const auto& kv : newState) state.set(kv.first, kv.second);
                    } else {
                        std::cerr << "Zip Drop Agent failed: " << out << "\n";
                    }
                } else {
                    std::cerr << "Failed to upload agent script.\n";
                }
                fs::remove("lily_agent.php");
            }
            fs::remove(".lily_payload.zip");
        } else {
            // Fallback to batched FTP
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
                            newState.erase(f);
                        }
                    }
                });
            }
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
    if (downloadedCount > 0) {
        auto finalFiles = scanLocalDirectory(".");
        for (const auto& file : finalFiles) {
            state.set(file, Hash::sha1_file(file));
        }
        std::cout << "Pull complete! " << downloadedCount << " files downloaded.\n";
    } else {
        std::cout << "Pull complete! 0 files downloaded.\n";
    }
}

void doBackup(FtpClient& ftp, const std::string& appUrl) {
    if (appUrl.empty()) {
        std::cerr << "Error: APP_URL must be set in .env for high-speed backup.\n";
        return;
    }
    std::cout << "Uploading backup agent...\n";
    std::string agent = "<?php\n"
        "$root = realpath(__DIR__ . '/../');\n"
        "$zipFile = __DIR__ . '/../server_backup.zip';\n"
        "$z = new ZipArchive();\n"
        "if ($z->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {\n"
        "    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root), RecursiveIteratorIterator::LEAVES_ONLY);\n"
        "    foreach ($files as $name => $file) {\n"
        "        if (!$file->isDir()) {\n"
        "            $fp = $file->getRealPath();\n"
        "            $rel = substr($fp, strlen($root) + 1);\n"
        "            if (strpos($rel, 'server_backup.zip') === false) $z->addFile($fp, $rel);\n"
        "        }\n"
        "    }\n"
        "    $z->close();\n"
        "}\n"
        "unlink(__FILE__);\n"
        "echo 'SUCCESS';\n";
        
    std::ofstream aout("lily_backup_agent.php"); aout << agent; aout.close();
    if (ftp.upload("lily_backup_agent.php", "public/lily_backup_agent.php")) {
        std::cout << "Triggering high-speed remote backup...\n";
        bool succ;
        std::string out = ftp.execCommand("curl.exe -sS \"" + appUrl + "/lily_backup_agent.php\"", succ);
        if (out.find("SUCCESS") != std::string::npos) {
            std::cout << "Downloading server_backup.zip...\n";
            if (ftp.download("server_backup.zip", "server_backup.zip")) {
                std::cout << "Backup downloaded to server_backup.zip successfully!\n";
                ftp.deleteFile("server_backup.zip");
            } else {
                std::cerr << "Failed to download backup.\n";
            }
        } else {
            std::cerr << "Backup agent failed: " << out << "\n";
        }
    } else {
        std::cerr << "Failed to upload backup agent.\n";
    }
    fs::remove("lily_backup_agent.php");
}

int main(int argc, char* argv[]) {
    if (argc < 2) {
        std::cerr << "Usage: lilyassist <push|pull|backup|wipe> [-f|--force]\n";
        return 1;
    }
    
    std::string command = argv[1];
    bool force = false;
    for (int i = 2; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "-f" || arg == "--force") force = true;
    }
    
    if (command != "push" && command != "pull" && command != "backup" && command != "wipe") {
        std::cerr << "Invalid command. Use 'push', 'pull', 'backup', or 'wipe'.\n";
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
    
    std::string appUrl = env.get("APP_URL", "");
    if (!appUrl.empty() && appUrl.back() == '/') {
        appUrl.pop_back();
    }

    FtpClient ftp(host, port, user, pass, root, secure);
    State state(".lily_sync_state.json");
    
    if (command == "push") {
        doPush(ftp, state, force, concurrency, appUrl);
    } else if (command == "pull") {
        doPull(ftp, state, force, concurrency);
    } else if (command == "backup") {
        doBackup(ftp, appUrl);
    } else if (command == "wipe") {
        doWipe(ftp, appUrl);
    }
    
    return 0;
}
