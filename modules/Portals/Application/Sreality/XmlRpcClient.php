<?php

declare(strict_types=1);

namespace Modules\Portals\Application\Sreality;

/**
 * Minimální XML-RPC klient (UTF-8) pro Sreality import.sreality.cz/RPC2.
 */
final class XmlRpcClient
{
    private const UA = 'GEREQ-Portals/1.3 (+sreality-xmlrpc)';

    public function __construct(
        private readonly string $endpoint = 'https://import.sreality.cz/RPC2',
        private readonly int $timeout = 60,
    ) {
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $body = $this->encodeRequest($method, $params);
        $raw = $this->post($body);
        $decoded = $this->decodeResponse($raw);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Sreality XML-RPC: neočekávaná odpověď.');
        }

        return $decoded;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /** @param list<mixed> $params */
    private function encodeRequest(string $method, array $params): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<methodCall><methodName>' . $this->escape($method) . '</methodName><params>';
        foreach ($params as $param) {
            $xml .= '<param><value>' . $this->encodeValue($param) . '</value></param>';
        }
        $xml .= '</params></methodCall>';

        return $xml;
    }

    private function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return '<string></string>';
        }
        if (is_bool($value)) {
            return '<boolean>' . ($value ? '1' : '0') . '</boolean>';
        }
        if (is_int($value)) {
            return '<int>' . $value . '</int>';
        }
        if (is_float($value)) {
            return '<double>' . $value . '</double>';
        }
        if ($value instanceof XmlRpcBinary) {
            return '<base64>' . $value->base64 . '</base64>';
        }
        if (is_string($value)) {
            return '<string>' . $this->escape($value) . '</string>';
        }
        if ($value instanceof \DateTimeInterface) {
            return '<dateTime.iso8601>' . $value->format('Ymd\\TH:i:s') . '</dateTime.iso8601>';
        }
        if (is_array($value)) {
            if ($this->isAssoc($value)) {
                $xml = '<struct>';
                foreach ($value as $k => $v) {
                    $xml .= '<member><name>' . $this->escape((string) $k) . '</name><value>';
                    $xml .= $this->encodeValue($v);
                    $xml .= '</value></member>';
                }
                $xml .= '</struct>';

                return $xml;
            }
            $xml = '<array><data>';
            foreach ($value as $v) {
                $xml .= '<value>' . $this->encodeValue($v) . '</value>';
            }
            $xml .= '</data></array>';

            return $xml;
        }

        return '<string>' . $this->escape((string) $value) . '</string>';
    }

    /** @return array<string, mixed>|list<mixed>|string|int|float|bool|null */
    private function decodeResponse(string $raw): mixed
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new \RuntimeException('Sreality XML-RPC: neplatné XML.');
        }
        if (isset($doc->fault)) {
            $fault = $this->decodeValue($doc->fault->value);
            $msg = is_array($fault)
                ? (string) ($fault['faultString'] ?? json_encode($fault, JSON_UNESCAPED_UNICODE))
                : (string) $fault;
            throw new \RuntimeException('Sreality XML-RPC fault: ' . $msg);
        }
        if (!isset($doc->params->param->value)) {
            throw new \RuntimeException('Sreality XML-RPC: chybí params.');
        }

        return $this->decodeValue($doc->params->param->value);
    }

    /** @return array<string, mixed>|list<mixed>|string|int|float|bool|null */
    private function decodeValue(\SimpleXMLElement $value): mixed
    {
        if (isset($value->struct)) {
            $out = [];
            foreach ($value->struct->member as $member) {
                $name = (string) $member->name;
                $out[$name] = $this->decodeValue($member->value);
            }

            return $out;
        }
        if (isset($value->array)) {
            $out = [];
            if (isset($value->array->data->value)) {
                foreach ($value->array->data->value as $item) {
                    $out[] = $this->decodeValue($item);
                }
            }

            return $out;
        }
        if (isset($value->string)) {
            return (string) $value->string;
        }
        if (isset($value->int)) {
            return (int) (string) $value->int;
        }
        if (isset($value->i4)) {
            return (int) (string) $value->i4;
        }
        if (isset($value->boolean)) {
            return ((string) $value->boolean) === '1';
        }
        if (isset($value->double)) {
            return (float) (string) $value->double;
        }
        if (isset($value->base64)) {
            return (string) $value->base64;
        }
        if (isset($value->{'dateTime.iso8601'})) {
            return (string) $value->{'dateTime.iso8601'};
        }
        // bare text node
        $text = trim((string) $value);
        if ($text !== '' && count($value->children()) === 0) {
            return $text;
        }

        return null;
    }

    private function post(string $body): string
    {
        $headers = [
            'Content-Type: text/xml; charset=UTF-8',
            'User-Agent: ' . self::UA,
            'Accept: text/xml',
        ];
        if (function_exists('curl_init')) {
            $ch = curl_init($this->endpoint);
            if ($ch === false) {
                throw new \RuntimeException('Nelze inicializovat cURL.');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new \RuntimeException('Sreality HTTP selhalo' . ($error !== '' ? ': ' . $error : '.'));
            }
            if ($status >= 400) {
                throw new \RuntimeException('Sreality HTTP ' . $status);
            }

            return (string) $raw;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->timeout,
            ],
        ]);
        $raw = @file_get_contents($this->endpoint, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('Sreality HTTP selhalo (streams).');
        }

        return $raw;
    }

    /** @param array<mixed> $arr */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
