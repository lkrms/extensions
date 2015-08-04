function FindProxyForURL(url,host){
	if (isPlainHostName(host))
		return "DIRECT";
	var r = /(^|\.)(local(\.)?|mydomain\.com|mydomain\.net|apple\.com|icloud\.com|mzstatic\.com)$/i;
	if (r.test(host))
		return "DIRECT";
	var ip = dnsResolve(host);
	if (isInNet(ip,"10.0.0.0","255.0.0.0")||isInNet(ip,"127.0.0.0","255.0.0.0")||isInNet(ip,"172.16.0.0","255.240.0.0")||isInNet(ip,"192.168.0.0","255.255.0.0")) {
		return "DIRECT";
	}
	return "PROXY proxy.mydomain.net:{PORT}";
}