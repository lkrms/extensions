function FindProxyForURL(url,host){
	if (isPlainHostName(host))
		return "DIRECT";
	var r = /(^|\.)(local(\.)?|mydomain\.com|mydomain\.net)$/i;
	if (r.test(host))
		return "DIRECT";
	var ip = dnsResolve(host);
	if (isInNet(ip,"127.0.0.0","255.0.0.0")) {
		return "DIRECT";
	}
	return "PROXY proxy.mydomain.net:3128";
}