#pragma once
#include <string>
#include <fstream>
#include <iomanip>
#include <sstream>
#include <windows.h>
#include <wincrypt.h>

class Hash {
public:
    static std::string sha1_file(const std::string& filepath) {
        std::ifstream file(filepath, std::ios::binary);
        if (!file) return "";

        HCRYPTPROV hProv = 0;
        HCRYPTHASH hHash = 0;

        if (!CryptAcquireContext(&hProv, NULL, NULL, PROV_RSA_FULL, CRYPT_VERIFYCONTEXT)) return "";
        if (!CryptCreateHash(hProv, CALG_SHA1, 0, 0, &hHash)) {
            CryptReleaseContext(hProv, 0);
            return "";
        }

        char buffer[8192];
        while (file.read(buffer, sizeof(buffer))) {
            CryptHashData(hHash, (BYTE*)buffer, file.gcount(), 0);
        }
        if (file.gcount() > 0) {
            CryptHashData(hHash, (BYTE*)buffer, file.gcount(), 0);
        }

        DWORD hashLen = 20; // SHA1 is 20 bytes
        BYTE hashVal[20];
        CryptGetHashParam(hHash, HP_HASHVAL, hashVal, &hashLen, 0);

        CryptDestroyHash(hHash);
        CryptReleaseContext(hProv, 0);

        std::stringstream ss;
        for (DWORD i = 0; i < hashLen; i++) {
            ss << std::hex << std::setw(2) << std::setfill('0') << (int)hashVal[i];
        }
        return ss.str();
    }
};
