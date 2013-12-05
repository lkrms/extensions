#!/bin/bash

SCRIPTDIR=`dirname $0`
SETTINGSFILE=$SCRIPTDIR/settings

if [ ! -f "$SETTINGSFILE" ]; then

    echo "$SETTINGSFILE does not exist! Aborting."
    exit 1

fi

VMNAME=
VRDEPORT=
OSTYPE=Ubuntu_64
INITIALISO=~/isos/ubuntu-12.04.2-server-amd64.iso
MEMORY=2048
CPUS=2
BRIDGEON=eth0
HDSIZE=10000
AUTOSTART=on
AUTOSTARTDELAY=60

. "$SETTINGSFILE"

USAGEMESSAGE="Usage: vm_create_guest.sh -n VMNAME -v VRDEPORT [-o OSTYPE=$OSTYPE -i INITIALISO=$INITIALISO -m MEMORY=$MEMORY -c CPUS=$CPUS -b BRIDGEINTERFACE=$BRIDGEON -h HDSIZE=$HDSIZE -a AUTOSTARTENABLED=$AUTOSTART -d AUTOSTARTDELAY=$AUTOSTARTDELAY]"

OPTERR=0

while getopts ":n:v:o:i:m:c:b:h:a:d:" opt; do

    case $opt in

        n)
            VMNAME=$OPTARG
            ;;

        v)
            VRDEPORT=$OPTARG
            ;;

        o)
            OSTYPE=$OPTARG
            ;;

        i)
            INITIALISO=$OPTARG
            ;;

        m)
            MEMORY=$OPTARG
            ;;

        c)
            CPUS=$OPTARG
            ;;

        b)
            BRIDGEON=$OPTARG
            ;;

        h)
            HDSIZE=$OPTARG
            ;;

        a)
            AUTOSTART=$OPTARG
            ;;

        d)
            AUTOSTARTDELAY=$OPTARG
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

if [ -z "$VMNAME" -o -z "$VRDEPORT" ]; then

    echo $USAGEMESSAGE
    exit 0

fi

mkdir -p "$VDIROOT/$VMNAME/Snapshots"

vboxmanage createvm --name "$VMNAME" --ostype "$OSTYPE" --register

vboxmanage modifyvm "$VMNAME" --memory $MEMORY --cpus $CPUS --nic1 bridged --bridgeadapter1 $BRIDGEON --vrde on --vrdeauthtype external --vrdeport $VRDEPORT --snapshotfolder "$VDIROOT/$VMNAME/Snapshots" --autostart-enabled $AUTOSTART --autostart-delay $AUTOSTARTDELAY

vboxmanage createhd --filename "$VDIROOT/$VMNAME/sata00.vdi" --size $HDSIZE

vboxmanage storagectl "$VMNAME" --name "SATA Controller" --add sata --controller IntelAhci --hostiocache off --bootable on

vboxmanage storageattach "$VMNAME" --storagectl "SATA Controller" --port 0 --device 0 --type hdd --medium "$VDIROOT/$VMNAME/sata00.vdi"

vboxmanage storagectl "$VMNAME" --name "IDE Controller" --add ide --controller PIIX4 --bootable on

vboxmanage storageattach "$VMNAME" --storagectl "IDE Controller" --port 0 --device 0 --type dvddrive --medium $INITIALISO

