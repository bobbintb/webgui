define("ace/mode/latex_highlight_rules",["require","exports","module","ace/lib/oop","ace/mode/text_highlight_rules"],function(e,t,n){"use strict";var r=e("../lib/oop"),i=e("./text_highlight_rules").TextHighlightRules,s=function(){this.$rules={start:[{token:"comment",regex:"%.*$"},{token:["keyword","lparen","variable.parameter","rparen","lparen","storage.type","rparen"],regex:"(\\\\(?:documentclass|usepackage|input))(?:(\\[)([^\\]]*)(\\]))?({)([^}]*)(})"},{token:["keyword","lparen","variable.parameter","rparen"],regex:"(\\\\(?:label|v?ref|cite(?:[^{]*)))(?:({)([^}]*)(}))?"},{token:["storage.type","lparen","variable.parameter","rparen"],regex:"(\\\\begin)({)(verbatim)(})",next:"verbatim"},{token:["storage.type","lparen","variable.parameter","rparen"],regex:"(\\\\begin)({)(lstlisting)(})",next:"lstlisting"},{token:["storage.type","lparen","variable.parameter","rparen"],regex:"(\\\\(?:begin|end))({)([\\w*]*)(})"},{token:"storage.type",regex:/\\verb\b\*?/,next:[{token:["keyword.operator","string","keyword.operator"],regex:"(.)(.*?)(\\1|$)|",next:"start"}]},{token:"storage.type",regex:"\\\\[a-zA-Z]+"},{token:"lparen",regex:"[[({]"},{token:"rparen",regex:"[\\])}]"},{token:"constant.character.escape",regex:"\\\\[^a-zA-Z]?"},{token:"string",regex:"\\${1,2}",next:"equation"}],equation:[{token:"comment",regex:"%.*$"},{token:"string",regex:"\\${1,2}",next:"start"},{token:"constant.character.escape",regex:"\\\\(?:[^a-zA-Z]|[a-zA-Z]+)"},{token:"error",regex:"^\\s*$",next:"start"},{defaultToken:"string"}],verbatim:[{token:["storage.type","lparen","variable.parameter","rparen"],regex:"(\\\\end)({)(verbatim)(})",next:"start"},{defaultToken:"text"}],lstlisting:[{token:["storage.type","lparen","variable.parameter","rparen"],regex:"(\\\\end)({)(lstlisting)(})",next:"start"},{defaultToken:"text"}]},this.normalizeRules()};r.inherits(s,i),t.LatexHighlightRules=s}),define("ace/mode/rdoc_highlight_rules",["require","exports","module","ace/lib/oop","ace/lib/lang","ace/mode/text_highlight_rules","ace/mode/latex_highlight_rules"],function(e,t,n){"use strict";var r=e("../lib/oop"),i=e("../lib/lang"),s=e("./text_highlight_rules").TextHighlightRules,o=e("./latex_highlight_rules"),u=function(){this.$rules={start:[{token:"comment",regex:"%.*$"},{token:"text",regex:"\\\\[$&%#\\{\\}]"},{token:"keyword",regex:"\\\\(?:name|alias|method|S3method|S4method|item|code|preformatted|kbd|pkg|var|env|option|command|author|email|url|source|cite|acronym|href|code|preformatted|link|eqn|deqn|keyword|usage|examples|dontrun|dontshow|figure|if|ifelse|Sexpr|RdOpts|inputencoding|usepackage)\\b",next:"nospell"},{token:"keyword",regex:"\\\\(?:[a-zA-Z0-9]+|[^a-zA-Z0-9])"},{token:"paren.keyword.operator",regex:"[[({]"},{token:"paren.keyword.operator",regex:"[\\])}]"},{token:"text",regex:"\\s+"}],nospell:[{token:"comment",regex:"%.*$",next:"start"},{token:"nospell.text",regex:"\\\\[$&%#\\{\\}]"},{token:"keyword",regex:"\\\\(?:name|alias|method|S3method|S4method|item|code|preformatted|kbd|pkg|var|env|option|command|author|email|url|source|cite|acronym|href|code|preformatted|link|eqn|deqn|keyword|usage|examples|dontrun|dontshow|figure|if|ifelse|Sexpr|RdOpts|inputencoding|usepackage)\\b"},{token:"keyword",regex:"\\\\(?:[a-zA-Z0-9]+|[^a-zA-Z0-9])",next:"start"},{token:"paren.keyword.operator",regex:"[[({]"},{token:"paren.keyword.operator",regex:"[\\])]"},{token:"paren.keyword.operator",regex:"}",next:"start"},{token:"nospell.text",regex:"\\s+"},{token:"nospell.text",regex:"\\w+"}]}};r.inherits(u,s),t.RDocHighlightRules=u}),define("ace/mode/matching_brace_outdent",["require","exports","module","ace/range"],function(e,t,n){"use strict";var r=e("../range").Range,i=function(){};(function(){this.checkOutdent=function(e,t){return/^\s+$/.test(e)?/^\s*\}/.test(t):!1},this.autoOutdent=function(e,t){var n=e.getLine(t),i=n.match(/^(\s*\})/);if(!i)return 0;var s=i[1].length,o=e.findMatchingBracket({row:t,column:s});if(!o||o.row==t)return 0;var u=this.$getIndent(e.getLine(o.row));e.replace(new r(t,0,t,s-1),u)},this.$getIndent=function(e){return e.match(/^\s*/)[0]}}).call(i.prototype),t.MatchingBraceOutdent=i}),define("ace/mode/rdoc",["require","exports","module","ace/lib/oop","ace/mode/text","ace/mode/rdoc_highlight_rules","ace/mode/matching_brace_outdent"],function(e,t,n){"use strict";var r=e("../lib/oop"),i=e("./text").Mode,s=e("./rdoc_highlight_rules").RDocHighlightRules,o=e("./matching_brace_outdent").MatchingBraceOutdent,u=function(e){this.HighlightRules=s,this.$outdent=new o,this.$behaviour=this.$defaultBehaviour};r.inherits(u,i),function(){this.getNextLineIndent=function(e,t,n){return this.$getIndent(t)},this.$id="ace/mode/rdoc"}.call(u.prototype),t.Mode=u});                (function() {
                    window.require(["ace/mode/rdoc"], function(m) {
                        if (typeof module == "object" && typeof exports == "object" && module) {
                            module.exports = m;
                        }
                    });
                })();