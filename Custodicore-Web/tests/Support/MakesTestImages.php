<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * A real (1×1) PNG upload. UploadedFile::fake()->image() needs the GD
 * extension, which isn't installed on every dev machine; this doesn't.
 */
trait MakesTestImages
{
    protected function fakePhoto(string $name = 'photo.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
