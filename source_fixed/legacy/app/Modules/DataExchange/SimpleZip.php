<?php

/**
 * ZIP reader/writer kecil tanpa ekstensi ZipArchive.
 * Mendukung ZIP biasa (store/deflate) yang digunakan file XLSX.
 */
class KpSimpleZipWriter
{
    private array $entries = [];

    public function addFile(string $name, string $data): void
    {
        $name = str_replace('\\', '/', ltrim($name, '/'));
        if ($name === '' || strpos($name, '../') !== false) {
            throw new InvalidArgumentException('Nama file ZIP tidak valid.');
        }
        $this->entries[] = ['name' => $name, 'data' => $data];
    }

    public function build(): string
    {
        $body = '';
        $central = '';
        $offset = 0;
        [$dosTime, $dosDate] = $this->dosDateTime();

        foreach ($this->entries as $entry) {
            $name = $entry['name'];
            $data = $entry['data'];
            $compressed = gzdeflate($data, 9);
            if ($compressed === false) {
                throw new RuntimeException('Gagal mengompresi file ZIP.');
            }

            $crc = crc32($data);
            $compressedSize = strlen($compressed);
            $size = strlen($data);
            $nameLength = strlen($name);

            $local = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                8,
                $dosTime,
                $dosDate,
                $crc,
                $compressedSize,
                $size,
                $nameLength,
                0
            ) . $name . $compressed;

            $body .= $local;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                8,
                $dosTime,
                $dosDate,
                $crc,
                $compressedSize,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            ) . $name;

            $offset += strlen($local);
        }

        $count = count($this->entries);
        $end = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            strlen($body),
            0
        );

        return $body . $central . $end;
    }

    private function dosDateTime(): array
    {
        $year = max(1980, (int)date('Y'));
        $month = (int)date('n');
        $day = (int)date('j');
        $hour = (int)date('G');
        $minute = (int)date('i');
        $second = (int)date('s');

        $time = ($hour << 11) | ($minute << 5) | intdiv($second, 2);
        $date = (($year - 1980) << 9) | ($month << 5) | $day;
        return [$time, $date];
    }
}

class KpSimpleZipReader
{
    private string $data;
    private array $entries = [];
    private int $maxEntrySize;
    private int $maxTotalSize;

    public function __construct(string $path, int $maxEntrySize = 20971520, int $maxTotalSize = 41943040)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('File ZIP tidak bisa dibaca.');
        }
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > 15728640) {
            throw new RuntimeException('Ukuran file Excel tidak valid atau terlalu besar.');
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Gagal membaca file Excel.');
        }
        $this->data = $data;
        $this->maxEntrySize = $maxEntrySize;
        $this->maxTotalSize = $maxTotalSize;
        $this->parseCentralDirectory();
    }

    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    public function get(string $name): ?string
    {
        if (!isset($this->entries[$name])) {
            return null;
        }
        $entry = $this->entries[$name];
        $offset = $entry['offset'];
        $header = substr($this->data, $offset, 30);
        if (strlen($header) !== 30) {
            throw new RuntimeException('Header ZIP rusak.');
        }
        $info = unpack('Vsig/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLength/vextraLength', $header);
        if (!$info || $info['sig'] !== 0x04034b50) {
            throw new RuntimeException('Format ZIP tidak valid.');
        }
        if (($info['flags'] & 1) === 1) {
            throw new RuntimeException('File Excel terenkripsi tidak didukung.');
        }

        $dataOffset = $offset + 30 + $info['nameLength'] + $info['extraLength'];
        $compressed = substr($this->data, $dataOffset, $entry['compressed_size']);
        if (strlen($compressed) !== $entry['compressed_size']) {
            throw new RuntimeException('Isi ZIP tidak lengkap.');
        }

        if ($entry['method'] === 0) {
            $output = $compressed;
        } elseif ($entry['method'] === 8) {
            $output = gzinflate($compressed, $this->maxEntrySize + 1);
            if ($output === false) {
                throw new RuntimeException('Gagal membuka kompresi file Excel.');
            }
        } else {
            throw new RuntimeException('Metode kompresi Excel tidak didukung.');
        }

        if (strlen($output) > $this->maxEntrySize || strlen($output) !== $entry['size']) {
            throw new RuntimeException('Isi file Excel tidak valid atau terlalu besar.');
        }
        return $output;
    }

    private function parseCentralDirectory(): void
    {
        $eocdPos = strrpos($this->data, "PK\x05\x06");
        if ($eocdPos === false) {
            throw new RuntimeException('File bukan ZIP/XLSX yang valid.');
        }
        $eocd = substr($this->data, $eocdPos, 22);
        $end = unpack('Vsig/vdisk/vstartDisk/ventriesDisk/ventries/VcentralSize/VcentralOffset/vcommentLength', $eocd);
        if (!$end || $end['sig'] !== 0x06054b50) {
            throw new RuntimeException('Direktori ZIP tidak valid.');
        }
        if ($end['entries'] > 1000 || $end['centralOffset'] + $end['centralSize'] > strlen($this->data)) {
            throw new RuntimeException('Struktur file Excel tidak aman.');
        }

        $pos = $end['centralOffset'];
        $totalUncompressed = 0;
        for ($i = 0; $i < $end['entries']; $i++) {
            $header = substr($this->data, $pos, 46);
            if (strlen($header) !== 46) {
                throw new RuntimeException('Direktori file Excel rusak.');
            }
            $info = unpack('Vsig/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset', $header);
            if (!$info || $info['sig'] !== 0x02014b50) {
                throw new RuntimeException('Entri ZIP tidak valid.');
            }
            $name = substr($this->data, $pos + 46, $info['nameLength']);
            $pos += 46 + $info['nameLength'] + $info['extraLength'] + $info['commentLength'];

            if ($info['usize'] > $this->maxEntrySize) {
                throw new RuntimeException('Salah satu bagian Excel terlalu besar.');
            }
            $totalUncompressed += $info['usize'];
            if ($totalUncompressed > $this->maxTotalSize) {
                throw new RuntimeException('Total isi Excel terlalu besar.');
            }

            $this->entries[$name] = [
                'method' => $info['method'],
                'compressed_size' => $info['csize'],
                'size' => $info['usize'],
                'offset' => $info['offset'],
            ];
        }
    }
}
