        function expand(id, type, url) {
                var doc = document.getElementById(id);
                if(doc.className=="hidden") {
                        if( type == "slideshare" ) {
                                //url with be given as url encoded string, so it will need to be decoded first
		                var decoded = decodeURIComponent(url.replace(/\+/g, ' '));
				doc.innerHTML = decoded;
                                } else if (type == "youtube") {
                                        var link = url;
                                        var begin = link.search("watch");
                                        var suffix = link.substring(begin + 8, url.length);

                                        var embed = '<iframe width=\"560\" height=\"315\" src=\"https://www.youtube.com/embed/' + suffix + '\
\" frameborder=\"0\"></iframe>'

                                        doc.innerHTML = embed;
                                }
                        doc.className="expanded";
                } else if (doc.className=="expanded") {
                        doc.className="hidden";
                }
	}

