' ShutdownOU.vbs
' Queries the given Active Directory OU for computer records, and uses shutdown.exe to [ruthlessly] power them all down.

Option Explicit

Dim args
Set args = WScript.Arguments

If args.Length <> 1 Then

    WScript.Echo "Please provide a single OU on the command line."
    WScript.Quit

End If

Dim ou
Set ou = GetObject("LDAP://" & args(0))
ou.Filter = Array("computer")

WScript.Echo "Attempting to power down all computers in " & ou.ou & " (" & ou.distinguishedName & ")..."

Dim wmi, wsh
Set wmi = GetObject("winmgmts:\\.\root\cimv2")
Set wsh = WScript.CreateObject("WScript.Shell")

On Error Resume Next

Dim computer, name, fqdn, pings, ping
For Each computer In ou

    name = computer.cn
    fqdn = computer.dNSHostName
    WScript.StdOut.Write name & "..."

    Set pings = wmi.ExecQuery("select * from Win32_PingStatus where Address = '" & fqdn & "'")

    If Err = 0 Then

        For Each ping In pings

            If Not IsNull(ping.StatusCode) And ping.StatusCode = 0 Then

                WScript.StdOut.Write "online..."

                If wsh.Run("shutdown /s /t 120 /c ""To save energy, this computer will power down in 2 minutes."" /m \\" & fqdn, 7, True) = 0 Then

                    WScript.StdOut.Write "powering down..."

                Else

                    WScript.StdOut.Write "unable to send power down request..."

                End If

            Else

                WScript.StdOut.Write "offline..."

            End If

        Next

    Else

        WScript.StdOut.Write "unknown..."

    End If

    WScript.StdOut.WriteLine

Next

