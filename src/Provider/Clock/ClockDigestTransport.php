<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockDigestTransport
{
    /** @var ClockRateLimiter */
    private $rateLimiter;

    public function __construct(?ClockRateLimiter $rateLimiter = null)
    {
        $this->rateLimiter = $rateLimiter ?: new ClockRateLimiter();
    }

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

        $this->rateLimiter->acquire();
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

        $this->rateLimiter->acquire();
        $response = \wp_remote_request($url, $args);

        /*
         * A Digest nonce may expire between requests. A 401 response proves
         * that the write was not authorized, so one challenge refresh is safe
         * even for POST/PUT calls and avoids surfacing a false provider error.
         */
        $responseStatus = \is_wp_error($response) ? 0 : (int) \wp_remote_retrieve_response_code($response);
        if ($responseStatus !== 401) {
            return $response;
        }

        $refreshedChallenge = (string) \wp_remote_retrieve_header($response, 'www-authenticate');
        if ($refreshedChallenge === '' || \stripos($refreshedChallenge, 'digest') === false) {
            return $response;
        }

        $headers['Authorization'] = $this->buildDigestHeader(
            $method,
            $url,
            $refreshedChallenge,
            ClockConfig::apiUser(),
            ClockConfig::apiKey()
        );
        if ($headers['Authorization'] === '') {
            return $response;
        }

        $args['headers'] = $headers;
        $this->rateLimiter->acquire();

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
        $algorithm = $algorithm !== '' ? $algorithm : 'MD5';
        $sessionAlgorithm = \substr($algorithm, -5) === '-SESS';
        $baseAlgorithm = $sessionAlgorithm ? \substr($algorithm, 0, -5) : $algorithm;
        $hashAlgorithm = $baseAlgorithm === 'MD5'
            ? 'md5'
            : ($baseAlgorithm === 'SHA-256' ? 'sha256' : ($baseAlgorithm === 'SHA-512-256' ? 'sha512/256' : ''));

        if ($hashAlgorithm === '' || !\in_array($hashAlgorithm, \hash_algos(), true)) {
            return '';
        }

        $uuid = \function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : \uniqid('', true);
        $cnonce = \substr(\hash($hashAlgorithm, $uuid), 0, 32);
        $nc = '00000001';
        $qopOptions = \array_filter(\array_map('trim', \explode(',', \strtolower($qop))));
        $qopValue = \in_array('auth', $qopOptions, true) ? 'auth' : '';

        if ($qop !== '' && $qopValue === '') {
            // auth-int needs the exact entity body hash and is not offered by Clock's documented examples.
            return '';
        }

        $ha1 = \hash($hashAlgorithm, $user . ':' . $realm . ':' . $key);
        if ($sessionAlgorithm) {
            $ha1 = \hash($hashAlgorithm, $ha1 . ':' . $nonce . ':' . $cnonce);
        }
        $ha2 = \hash($hashAlgorithm, $method . ':' . $uri);
        $response = $qopValue !== ''
            ? \hash($hashAlgorithm, $ha1 . ':' . $nonce . ':' . $nc . ':' . $cnonce . ':' . $qopValue . ':' . $ha2)
            : \hash($hashAlgorithm, $ha1 . ':' . $nonce . ':' . $ha2);

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

        if ($algorithm !== '') {
            $parts[] = 'algorithm=' . $algorithm;
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
        $digestAt = \stripos($challenge, 'Digest ');
        if ($digestAt === false) {
            return [];
        }
        $challenge = \trim(\substr($challenge, $digestAt + 7));
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
