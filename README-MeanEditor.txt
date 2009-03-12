== MeanEditor ==
MeanEditor is a lean-and-mean WYSIWYM (notice the M) editor based on [http://wymeditor.org WYMeditor]. It is meant to complement traditional editing, instead of implementing the full Mediawiki language. It provides non-technical and inexperienced users a way to contribute to a wiki while being:
* easy to use
* history-friendly
* fair to existing hand-written code and edits
* semantically correct, encouraging correct use of markup

See <http://www.mediawiki.org/wiki/Extension:MeanEditor> for updated information.

=== Rationale ===
Advanced users and administrators do not need a visual editor. They just need it not to interfere with the code they already know and love. Moreover, expressing the full mediawiki syntax in a visual editor is and probably impossible.

We focused on the "easy", non technical, parts of a wiki. We estimate that a lot of Wikipedia content consists of simple paragraphs of text with links, references, images and a little formatting. This is where inexperienced editors can provide valuable contributions with little effort.

=== Usage cases ===
We designed MeanEditor with existing wikis (above all, Wikipedia) in mind. We hope the existing communities will find MeanEditor a good tool they can trust, rather than find annoying.

If no advanced features are needed, MeanEditor is a good choice even for a single-editor wiki.

=== Installing ===
0) Install Mediawiki 1.14 in /var/www/mediawiki (see below if you are installing in another directory)
1) Unpack the tar in /var/www/mediawiki. This will create the jquery, wymeditor and extensions/MeanEditor directories.
2) Disable $wgHashedUploadDirectory as suggested in LocalSettings.php
3) Add to LocalSettings.php
 require_once( "$IP/extensions/MeanEditor/MeanEditor.php" );

If you wish to install in another subdirectory or enable short paths, you will have to replace "/mediawiki/" as needed in wymeditor/jquery.wymeditor.pack.js
Of course, /var/www is just an example for your DocumentRoot.

=== Details ===
MeanEditor is best described as "A quick and dirty hack with a couple of interesting properties". It supports only a very limited subset of the Wiki language, but does its job right. (Well, it tries)

It is an adaptation of [http://wymeditor.org WYMeditor], a semantic editor for strict XHTML. This encourages users to think in terms of what they mean, instead of trying to change what they see.

MeanEditor refuses to modify markup it does not understand and strives to preserve the original page intact. Ideally, edits made with MeanEditor should be indistinguishable from manual edits. If anything, a visual editor for Wikipedia should not create additional work for administrators.

In the current version, redundant whitespace is sometimes removed and some characters are replaced with numerical entities. Other than that, MeanEditor leaves a very clean diff.

=== Supported markup ===
This list should certainly grow, but not indiscriminately. We feel the wiki community should decide what should be implemented in the visual editor and what should be left out.

Right now we support:
* Headlines (== and ===)
* Division in paragraphs
* [[Page]]- and [[Page|text]]-style wikilinks
* [url] and [url text]-style external links
* Images (with easy selection of recently uploaded images) with default positioning
* Simple lists (no nesting)
* Bold and italic
* References (write support is very limited)

To be implemented:
* Better reference support
* Categories
* Recognition of significant whitespace (i.e. code sections)
* A way to specify image position and size
* Preserving original code whitespace to leave a very clean diff (but some changes may be desirable, check what current bots automatically correct)
* Make "Magic" links like http://www.example.com clickable?
* Standard bibliography
* LaTeX formula editing (TODO: just edit the LaTeX code? Or MathML for visual editing?)

To be implemented only for display:
* Templates

=== Known Bugs ===
* Squeezes multiple newlines in one, does not preserve redundant whitespace (again, this may be a good thing)
* The Javascript code has hardcoded page and image URLs. Also, for some functions URL are shown in the interface. It will still need polishing.
* Requires $wgHashedUploadDirectory to be off (should be easy to solve)
* Context dialog doesn't work if you change selection (should it?)
* Unsupported feature detection is simply draconic
* Unnecessarily escapes characters like tilde in URLs and converts HTML entities (WYMeditor or browser problem?)
* Unicode handling is untested
* Preview passes HTML to the editor. We try to convert it to wikicode as soon as possible, but some hooks or preview functions may be confused.
* If you are in the middle of a visual editing and something strange happens, we revert to traditional editing. There might be cases in which you get HTML instead of wikicode and have to start over. This should be a very rare case, but existing extensions need reviewing.

=== Security ===
Saved text passes through usual Mediawiki checks.

However, it might be possible to create a malicious page in wikicode and have MeanEditor create dangerous HTML. Our regular expressions are simple, but they should be well reviewed before deploying.
 
=== Future? ===
Right now MeanEditor does not use the Mediawiki parser. This is what ensures MeanEditor doesn't do silly things with markup it does not understand.

We recognize our simple regular expressions will ultimately become too limited. A good solution would be to integrate an "editor mode" in the parser. The "editor mode" should generate semantic XHTML targeted to a WYSIWYM editor.
