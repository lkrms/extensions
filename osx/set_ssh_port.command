#!/bin/bash

echo "21c
			<string>$1</string>
." | sudo patch -eb /System/Library/LaunchDaemons/ssh.plist

sudo launchctl unload /System/Library/LaunchDaemons/ssh.plist
sudo launchctl load /System/Library/LaunchDaemons/ssh.plist

