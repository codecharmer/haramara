/** Strip the Store API's HTML fragments down to plain text for native display. */
export function plainText(html: string): string {
	return html
		.replace(/<[^>]+>/g, ' ')
		.replace(/&nbsp;/g, ' ')
		.replace(/&amp;/g, '&')
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&#8211;|&ndash;/g, '–')
		.replace(/&#8217;|&rsquo;/g, "'")
		.replace(/&quot;|&#8220;|&#8221;/g, '"')
		.replace(/\s+/g, ' ')
		.trim();
}
