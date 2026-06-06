#pragma once
#include <string>
#include <unordered_map>
#include <fstream>
#include "json.hpp"

using json = nlohmann::json;

class State {
    std::string filepath;
    std::unordered_map<std::string, std::string> hashes;
public:
    State(const std::string& path) : filepath(path) {
        std::ifstream file(filepath);
        if (file.is_open()) {
            try {
                json j;
                file >> j;
                for (auto& [key, value] : j.items()) {
                    hashes[key] = value.get<std::string>();
                }
            } catch (...) {
                // ignore parsing errors
            }
        }
    }

    std::string get(const std::string& file) const {
        auto it = hashes.find(file);
        return it != hashes.end() ? it->second : "";
    }

    void set(const std::string& file, const std::string& hash) {
        hashes[file] = hash;
    }

    void remove(const std::string& file) {
        hashes.erase(file);
    }

    bool exists(const std::string& file) const {
        return hashes.find(file) != hashes.end();
    }

    void save() const {
        json j;
        for (const auto& [key, value] : hashes) {
            j[key] = value;
        }
        std::ofstream file(filepath);
        if (file.is_open()) {
            file << j.dump(4);
        }
    }

    const std::unordered_map<std::string, std::string>& getAll() const {
        return hashes;
    }
};
