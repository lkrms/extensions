#!/bin/bash

VMNAME=

USAGEMESSAGE="Usage: vb-mount-additions.sh -n VMNAME"

OPTERR=0

while getopts ":n:" opt; do

    case $opt in

        n)
            VMNAME=$OPTARG
            ;;

        \?)
            echo "Invalid argument: -$OPTARG"
            OPTERR=1
            ;;

        \:)
            echo "Value required: -$OPTARG"
            OPTERR=1
            ;;

    esac

done

if [ $OPTERR -eq 1 ]; then

    echo $USAGEMESSAGE
    exit 1

fi

if [ -z "$VMNAME" ]; then

    echo $USAGEMESSAGE
    exit 0

fi

vboxmanage storageattach "$VMNAME" --storagectl "IDE Controller" --port 0 --device 0 --type dvddrive --medium /usr/share/virtualbox/VBoxGuestAdditions.iso

