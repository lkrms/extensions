function FindProxyForURL(url,host){
	if (isPlainHostName(host))
		return "DIRECT";
	var r = /(^|\.)(local(\.)?|mydomain\.com|mydomain\.net|apple\.com|icloud\.com|mzstatic\.com)$/i;
	if (r.test(host))
		return "DIRECT";
	return "PROXY 127.0.0.1:65535";
}