<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class GrafanaProxy extends Controller
{
    /** Hop-by-hop-Header, die NIEMALS weitergereicht werden dürfen */
    private const HOP_HEADERS = [
        'connection','keep-alive','proxy-authenticate','proxy-authorization',
        'te','trailer','transfer-encoding','upgrade',
    ];

    public function __invoke(Request $request, string $path = '')
    {
        $user  = $request->user();
        $token = $user->grafana_token;
        abort_unless($token, 403, 'No Grafana token on user.');

        // Ziel-URL in Grafana
        $url = rtrim(config('services.grafana.url'), '/') . '/' . ltrim($path, '/');

        /* ---- eingehende Header vorbereiten ------------------------------ */
        // Nur sinnvolle Header übernehmen (z. B. Content-Type)
        $incoming = collect($request->headers->all())
            ->mapWithKeys(fn ($v, $k) => [$k => is_array($v) ? $v[0] : $v])
            ->reject(fn ($_, $k) => in_array(strtolower($k), self::HOP_HEADERS))
            ->only(['content-type']);

        // Zwingend: Auth & Org für Grafana
        $forwardHeaders = array_merge($incoming->all(), [
            'Authorization'     => 'Bearer ' . $token,
            'X-Grafana-Org-Id'  => (string) $user->grafana_org_id,
            'Accept'            => '*/*',
        ]);

        /* ---- Options (Query + Body) ------------------------------------- */
        /* ---- Options (Query + Body) ------------------------------------- */
        $options = ['query' => $request->query()];

// Für POST/PUT/PATCH/DELETE den Body korrekt weitergeben
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            $contentType = strtolower($request->header('content-type', ''));

            if (str_contains($contentType, 'application/json')) {
                // rohen JSON-Body durchreichen
                $options['body'] = $request->getContent();
            } else {
                // Fallback: klassische Form-Daten
                $options['form_params'] = $request->post();
            }
        }

        /* ---- Request an Grafana schicken -------------------------------- */
        $forward = Http::withHeaders($forwardHeaders)
            ->send($request->method(), $url, $options);


        /* ---- Hop-by-hop-Header aus der Antwort entfernen ---------------- */
        $respHeaders = collect($forward->headers())
            ->reject(fn ($_v, $k) => in_array(strtolower($k), self::HOP_HEADERS))
            ->all();

        return new Response($forward->body(), $forward->status(), $respHeaders);
    }
}
