/*! Plugixa Exam -- bundled third-party library, shipped unmodified.
 * Freemius WordPress SDK 2.13.4 -- GPL-3.0-only -- https://github.com/Freemius/wordpress-sdk
 * This file is NOT built from this plugin's source. Its human-readable source is the
 * upstream project above; the exact version is pinned in composer.lock.
 */
/*! Plugixa Exam -- bundled third-party library: Freemius WordPress SDK 2.13.4 (GPL-3.0-only) -- source: https://github.com/Freemius/wordpress-sdk */!function(n){n.extend({form:function(r,e,t){null==t&&(t="POST"),null==e&&(e={});var o=n("<form>").attr({method:t,action:r}).css({display:"none"}),a=function(r,e){if(n.isArray(e))for(var t=0;t<e.length;t++){var i=e[t];a(r+"[]",i)}else if("object"==typeof e)for(var f in e)e.hasOwnProperty(f)&&a(r+"["+f+"]",e[f]);else null!=e&&o.append(n("<input>").attr({type:"hidden",name:String(r),value:String(e)}))};for(var i in e)e.hasOwnProperty(i)&&a(i,e[i]);return o.appendTo("body")}})}(jQuery);