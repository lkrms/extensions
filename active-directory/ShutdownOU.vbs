' ShutdownOU.vbs
' Queries the given Active Directory OU for computer records, and uses shutdown.exe to [ruthlessly] power them all down.

Option Explicit

Const ADS_SCOPE_SUBTREE = 2

Dim args
Set args = WScript.Arguments

If args.Length <> 1 Then

    WScript.Echo "Please provide a single OU on the command line."
    WScript.Quit

End If

Sub DoShutdown (dn)

    WScript.Echo "Attempting to power down all computers in " & dn & "..."

    Dim wmi, wsh
    Set wmi = GetObject("winmgmts:\\.\root\cimv2")
    Set wsh = WScript.CreateObject("WScript.Shell")

	Dim conn, command, rs
	Set conn = CreateObject("ADODB.Connection")
	conn.Provider = "ADsDSOObject"
	conn.Open "Active Directory Provider"

	Set command = CreateObject("ADODB.Command")
	Set command.ActiveConnection = conn
	command.Properties("Page Size") = 1000
	command.Properties("SearchScope") = ADS_SCOPE_SUBTREE

	command.CommandText = "SELECT cn, dNSHostName FROM 'LDAP://" & dn & "' WHERE objectCategory='computer' ORDER BY cn"

	Set rs = command.Execute

	Dim name, fqdn, pings, ping
	While Not rs.EOF

		name = rs.Fields("cn").Value
		fqdn = rs.Fields("dNSHostName").Value

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

		rs.MoveNext

	Wend

End Sub

On Error Resume Next

DoShutdown args(0)

