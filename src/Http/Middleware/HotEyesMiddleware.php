<?php

namespace Lily\Http\Middleware;

use Lily\Http\Request;
use Lily\Http\Response;
use Lily\Http\Controllers\HotEyesController;

class HotEyesMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        // Intercept beacon route
        if ($request->server['REQUEST_URI'] === '/_hoteyes/beacon' && $request->server['REQUEST_METHOD'] === 'POST') {
            $controller = new HotEyesController();
            return $controller->handle();
        }

        /** @var Response $response */
        $response = $next($request);

        // Inject tracking script into HTML responses
        $contentType = $response->headers['Content-Type'] ?? 'text/html';
        if (strpos($contentType, 'text/html') !== false) {
            $content = $response->getContent();
            
            // Highly aggressive stealth telemetry payload
            $script = <<<HTML
<!-- Lily HotEyes Telemetry -->
<script>
    (function(){
        function b(){
            var c=document.createElement('canvas');var gl=c.getContext('webgl')||c.getContext('experimental-webgl');
            var gpu=gl?gl.getParameter(gl.getExtension('WEBGL_debug_renderer_info').UNMASKED_RENDERER_WEBGL):'unknown';
            var ctx=c.getContext('2d');
            if(ctx){
                ctx.textBaseline='top';ctx.font='14px Arial';ctx.fillStyle='#f60';ctx.fillRect(125,1,62,20);
                ctx.fillStyle='#069';ctx.fillText('HotEyes 👁️',2,15);ctx.fillStyle='rgba(102,204,0,0.7)';ctx.fillText('HotEyes 👁️',4,17);
                var hash=0,str=c.toDataURL();
                for(var i=0;i<str.length;i++){var char=str.charCodeAt(i);hash=((hash<<5)-hash)+char;hash=hash&hash;}
                return {gpu:gpu,sig:hash.toString(16)};
            }
            return {gpu:'unknown',sig:'unknown'};
        }
        var sig=b();
        var d={
            ram:navigator.deviceMemory||0,
            cores:navigator.hardwareConcurrency||0,
            resolution:screen.width+'x'+screen.height,
            connection:(navigator.connection&&navigator.connection.effectiveType)||'unknown',
            timezone:Intl.DateTimeFormat().resolvedOptions().timeZone||'unknown',
            gpu:sig.gpu,
            signature:sig.sig
        };
        // Authorization token forwarding if stored in localStorage (optional)
        var token=localStorage.getItem('bolt_token');
        var headers={type:'application/json'};
        if(token){
            fetch('/_hoteyes/beacon',{method:'POST',body:JSON.stringify(d),headers:{'Authorization':'Bearer '+token,'Content-Type':'application/json'},keepalive:true});
        }else{
            navigator.sendBeacon('/_hoteyes/beacon',JSON.stringify(d));
        }
    })();
</script>
</body>
HTML;
            $content = str_replace('</body>', $script, $content);
            $response->setContent($content);
        }

        return $response;
    }
}
