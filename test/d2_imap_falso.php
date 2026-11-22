<?php
declare(strict_types=1);

// ============================================================
//  Servidor IMAP de mentira para los casos del lote D2 (test/casos/d2.php y
//  test/casos_db/d2.php). No está en casos/ para que run_tests.php no lo
//  corra como un caso: lo cargan los dos con require_once.
//
//  Es un stream de PHP (d2imap://) que contesta como un servidor real a lo
//  que manda imap_cmd(): no abre ninguna conexión de red.
// ============================================================

if (!class_exists('D2ImapFalso')) {
    /**
     * Servidor IMAP de mentira, como stream de PHP (d2imap://). Contesta
     * SELECT, UID SEARCH, UID FETCH (cabeceras, TEXT y el correo entero, con
     * o sin parcial <0.n>) y LOGOUT a partir de self::$buzon (uid => correo
     * crudo). imap_cmd() lo usa igual que un socket real.
     */
    class D2ImapFalso
    {
        /** @var array<int,string> uid => correo crudo (CRLF) */
        public static array $buzon = [];
        public static ?int $validez = 1;
        /** @var array<int,bool> uids cuyo FETCH contesta NO (lectura que falla) */
        public static array $fallar = [];
        /** @var array<int,string> comandos recibidos, en orden */
        public static array $comandos = [];
        /** @var resource|null */
        public $context;
        private string $salida = '';

        public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool { return true; }
        public function stream_close(): void {}
        // Como un socket: no tener datos ahora no es fin de archivo.
        public function stream_eof(): bool { return false; }
        public function stream_stat(): array { return []; }
        public function stream_set_option(int $o, int $a, ?int $b): bool { return false; }
        public function stream_read(int $n): string
        {
            $r = substr($this->salida, 0, $n);
            $this->salida = (string) substr($this->salida, strlen($r));
            return $r;
        }
        public function stream_write(string $data): int
        {
            foreach (preg_split("/\r\n/", $data) ?: [] as $l) {
                if ($l === '') { continue; }
                [$tag, $cmd] = explode(' ', $l, 2) + [1 => ''];
                self::$comandos[] = $cmd;
                $this->salida .= self::responder($tag, $cmd);
            }
            return strlen($data);
        }

        private static function responder(string $tag, string $cmd): string
        {
            if (str_starts_with($cmd, 'SELECT')) {
                return '* ' . count(self::$buzon) . " EXISTS\r\n"
                     . (self::$validez !== null ? '* OK [UIDVALIDITY ' . self::$validez . "] UIDs valid\r\n" : '')
                     . "$tag OK [READ-WRITE] SELECT completed\r\n";
            }
            if (preg_match('/^UID SEARCH UID (\d+):\*/', $cmd, $m)) {
                $uids = array_filter(array_keys(self::$buzon), fn($u) => $u >= (int)$m[1]);
                // Como los servidores reales: `n:*` incluye siempre el último.
                if (self::$buzon) { $uids[] = max(array_keys(self::$buzon)); }
                return '* SEARCH ' . implode(' ', array_unique($uids)) . "\r\n$tag OK SEARCH completed\r\n";
            }
            if (str_starts_with($cmd, 'UID SEARCH')) {
                return '* SEARCH ' . implode(' ', array_keys(self::$buzon)) . "\r\n$tag OK SEARCH completed\r\n";
            }
            if (preg_match('/^UID FETCH (\d+) \(BODY\.PEEK\[(HEADER|TEXT|)\](?:<0\.(\d+)>)?\)/', $cmd, $m)) {
                $uid = (int)$m[1];
                if (!empty(self::$fallar[$uid])) { return "$tag NO [UNAVAILABLE] FETCH failed\r\n"; }
                if (!isset(self::$buzon[$uid])) { return "$tag OK FETCH completed\r\n"; }
                $crudo = self::$buzon[$uid];
                $pos = strpos($crudo, "\r\n\r\n");
                $dato = match ($m[2]) {
                    'HEADER' => $pos === false ? $crudo : substr($crudo, 0, $pos + 4),
                    'TEXT'   => $pos === false ? '' : substr($crudo, $pos + 4),
                    default  => $crudo,
                };
                if (isset($m[3]) && $m[3] !== '') { $dato = substr($dato, 0, (int)$m[3]); }
                $etiqueta = 'BODY[' . $m[2] . ']' . (isset($m[3]) && $m[3] !== '' ? '<0>' : '');
                return "* 1 FETCH (UID $uid $etiqueta {" . strlen($dato) . "}\r\n" . $dato
                     . " FLAGS (\\Seen))\r\n$tag OK FETCH completed\r\n";
            }
            if ($cmd === 'LOGOUT') { return "* BYE\r\n$tag OK LOGOUT completed\r\n"; }
            return "$tag BAD comando desconocido\r\n";
        }
    }
    stream_wrapper_register('d2imap', 'D2ImapFalso');

    /**
     * Abre una conexión con el servidor de mentira cargado con $buzon.
     * @return resource
     */
    function d2_imap_falso(array $buzon, ?int $validez = 1, array $fallar = [])
    {
        D2ImapFalso::$buzon = $buzon;
        D2ImapFalso::$validez = $validez;
        D2ImapFalso::$fallar = $fallar;
        D2ImapFalso::$comandos = [];
        return fopen('d2imap://casilla', 'r+');
    }

    /** Pasa un correo escrito con \n a CRLF, como viaja de verdad. */
    function d2_crlf(string $s): string
    {
        return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $s));
    }
}
