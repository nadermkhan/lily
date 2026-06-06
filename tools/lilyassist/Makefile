CXX = g++
CXXFLAGS = -std=c++20 -Wall -O3
LIBS = -lcrypt32

all: lily-deploy.exe

lily-deploy.exe: main.cpp Config.hpp Hash.hpp State.hpp FtpClient.hpp ThreadPool.hpp
	$(CXX) $(CXXFLAGS) -o lily-deploy.exe main.cpp $(LIBS)

clean:
	rm -f lily-deploy.exe
