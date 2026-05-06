<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockDigestTransport
{
    /**
     * WordPress HTTP API has no portable first-class Digest Auth abstraction, so this helper
     * performs the standard two-step challenge flow and only adds the computed Digest header.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public function request(string $url, array $args)
    {
        if (!\function_exists('wp_remote_request')) {
            return new \WP_Error('wordpress_http_missing', 'WordPress HTTP API is unavailable.');
        }

        $method = isset($args['method']) ? \strtoupper((string) $args['method']) : 'GET';
        $probeArgs = $args;
        unset($probeArgs['body']);
        $probeArgs['method'] = $method;

        $probe = \wp_remote_request($url, $probeArgs);
        $status = \is_wp_error($probe) ? 0 : (int) \wp_remote_retrieve_response_code($probe);

        if (\is_wp_error($probe) || $status !== 401) {
            return $probe;
        }

        $challenge = (string) \wp_remote_retrieve_header($probe, 'www-authenticate');

        if ($challenge === '' || \stripos($challenge, 'digest') === false) {
            return $probe;
        }

        $headers = isset($args['headers']) && \is_array($args['headers']) ? $args['headers'] : [];
        $headers['Authorization'] = $this->buildDigestHeader(
            $method,
            $url,
            $challenge,
            ClockConfig::apiUser(),
            ClockConfig::apiKey()
        );

        if ($headers['Authorization'] === '') {
            return new \WP_Error('digest_auth_failed', 'Unable to build Clock Digest authentication header.');
        }

        $args['headers'] = $headers;

        return \wp_remote_request($url, $args);
    }

    private function buildDigestHeader(string $method, string $url, string $challenge, string $user, string $key): string
    {
        $data = $this->parseChallenge($challenge);

        if ($user === '' || $key === '' || empty($data['realm']) || empty($data['nonce'])) {
            return '';
        }

        $uri = \wp_parse_url($url, PHP_URL_PATH);
        $query = \wp_parse_url($url, PHP_URL_QUERY);
        $uri = \is_string($uri) && $uri !== '' ? $uri : '/';

        if (\is_string($query) && $query !== '') {
            $uri .= '?' . $query;
        }

        $realm = (string) $data['realm'];
        $nonce = (string) $data['nonce'];
        $qop = isset($data['qop']) ? (string) $data['qop'] : '';
        $algorithm = isset($data['algorithm']) ? \strtoupper((string) $data['algorithm']) : 'MD5';

        if ($algorithm !== '' && $algorithm !== 'MD5') {
            return '';
        }

        $ha1 = \md5($user . ':' . $realm . ':' . $key);
        $ha2 = \md5($method . ':' . $uri);
        $cnonce = \substr(\md5(\wp_generate_uuid4()), 0, 16);
        $nc = '00000001';
        $qopValue = \stripos($qop, 'auth') !== false ? 'auth' : '';
        $response = $qopValue !== ''
            ? \md5($ha1 . ':' . $nonce . ':' . $nc . ':' . $cnonce . ':' . $qopValue . ':' . $ha2)
            : \md5($ha1 . ':' . $nonce . ':' . $ha2);

        $parts = [
            'Digest username="' . $this->quote($user) . '"',
            'realm="' . $this->quote($realm) . '"',
            'nonce="' . $this->quote($nonce) . '"',
            'uri="' . $this->quote($uri) . '"',
            'response="' . $response . '"',
        ];

        if (!empty($data['opaque'])) {
            $parts[] = 'opaque="' . $this->quote((string) $data['opaque']) . '"';
        }

        if ($qopValue !== '') {
            $parts[] = 'qop=' . $qopValue;
            $parts[] = 'nc=' . $nc;
            $parts[] = 'cnonce="' . $cnonce . '"';
        }

        return \implode(', ', $parts);
    }

    /** @return array<string, string> */
    private function parseChallenge(string $challenge): array
    {
        $challenge = \trim((string) \preg_replace('/^\s*Digest\s+/i', '', $challenge));
        $data = [];

        if (\preg_match_all('/([a-z0-9_\-]+)=("([^"]*)"|([^,\s]+))/i', $challenge, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $data[\strtolower((string) $match[1])] = isset($match[3]) && $match[3] !== '' ? (string) $match[3] : (string) $match[4];
            }
        }

        return $data;
    }

    private function quote(string $value): string
    {
        return \str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
