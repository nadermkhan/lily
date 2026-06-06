#pragma once
#include <string>
#include <unordered_map>
#include <fstream>
#include <sstream>

class Config {
    std::unordered_map<std::string, std::string> data;
public:
    Config(const std::string& envPath) {
        std::ifstream file(envPath);
        if (!file.is_open()) return;
        std::string line;
        while (std::getline(file, line)) {
            if (line.empty() || line[0] == '#') continue;
            auto pos = line.find('=');
            if (pos != std::string::npos) {
                std::string key = line.substr(0, pos);
                std::string val = line.substr(pos + 1);
                
                // trim right
                key.erase(key.find_last_not_of(" \n\r\t") + 1);
                val.erase(val.find_last_not_of(" \n\r\t") + 1);
                // trim left
                val.erase(0, val.find_first_not_of(" \n\r\t"));
                
                if (val.size() >= 2 && val.front() == '"' && val.back() == '"') {
                    val = val.substr(1, val.size() - 2);
                }
                data[key] = val;
            }
        }
    }

    std::string get(const std::string& key, const std::string& defaultVal = "") const {
        auto it = data.find(key);
        return it != data.end() ? it->second : defaultVal;
    }
    
    bool getBool(const std::string& key, bool defaultVal = false) const {
        std::string val = get(key);
        if (val.empty()) return defaultVal;
        return val == "true" || val == "1" || val == "yes";
    }
};
