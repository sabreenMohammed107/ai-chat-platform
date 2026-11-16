<?php

namespace App\Services\Knowledge;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Smalot\PdfParser\Parser;

/** The text of an uploaded PDF, TXT, MD or CSV file. The file itself is not kept. */
class FileText
{
    public function read(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $text = match ($extension) {
            'pdf' => (new Parser)->parseFile($file->getRealPath())->getText(),
            'txt', 'md', 'csv' => (string) file_get_contents($file->getRealPath()),
            default => throw new RuntimeException('Unsupported file type.'),
        };

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1256,ISO-8859-1');
        }
        $text = trim(preg_replace("/[ \t]+/u", ' ', $text));
        if (mb_strlen($text) < 20) {
            throw new RuntimeException('No text could be read from this file (a scanned PDF has no text).');
        }

        return $text;
    }
}
