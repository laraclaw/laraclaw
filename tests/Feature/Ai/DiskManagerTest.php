<?php

use App\Ai\Tools\DiskManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    Storage::fake('test-disk');
    Config::set('laraclaw.disk_manager.allowed_disks', ['test-disk']);
});

function dm(array $params): string
{
    return (string) (new DiskManager)->handle(new Request($params));
}

// — Operations —

it('lists files and directories', function () {
    Storage::disk('test-disk')->put('file.txt', 'hello');
    Storage::disk('test-disk')->makeDirectory('subdir');

    $result = dm(['operation' => 'list', 'disk' => 'test-disk', 'path' => '/']);

    $entries = json_decode($result, true);
    $names = array_column($entries, 'name');

    expect($names)->toContain('file.txt')
        ->and($names)->toContain('subdir');
});

it('reads a file', function () {
    Storage::disk('test-disk')->put('notes.txt', 'some content');

    $result = dm(['operation' => 'read', 'disk' => 'test-disk', 'path' => 'notes.txt']);

    expect($result)->toBe('some content');
});

it('truncates large files on read', function () {
    Storage::disk('test-disk')->put('big.txt', str_repeat('x', 120_000));

    $result = dm(['operation' => 'read', 'disk' => 'test-disk', 'path' => 'big.txt']);

    expect($result)->toContain('[Truncated')
        ->and(strlen($result))->toBeLessThan(120_000);
});

it('writes a file', function () {
    $result = dm(['operation' => 'write', 'disk' => 'test-disk', 'path' => 'new.txt', 'content' => 'hello']);

    expect($result)->toContain('Written')
        ->and(Storage::disk('test-disk')->get('new.txt'))->toBe('hello');
});

it('appends to a file', function () {
    Storage::disk('test-disk')->put('log.txt', 'line1');

    $result = dm(['operation' => 'append', 'disk' => 'test-disk', 'path' => 'log.txt', 'content' => 'line2']);

    expect($result)->toContain('Appended')
        ->and(Storage::disk('test-disk')->get('log.txt'))->toContain('line1')
        ->and(Storage::disk('test-disk')->get('log.txt'))->toContain('line2');
});

it('deletes a file', function () {
    Storage::disk('test-disk')->put('trash.txt', 'bye');

    $result = dm(['operation' => 'delete', 'disk' => 'test-disk', 'path' => 'trash.txt']);

    expect($result)->toContain('Deleted')
        ->and(Storage::disk('test-disk')->exists('trash.txt'))->toBeFalse();
});

it('moves a file', function () {
    Storage::disk('test-disk')->put('a.txt', 'data');

    $result = dm(['operation' => 'move', 'disk' => 'test-disk', 'path' => 'a.txt', 'destination' => 'b.txt']);

    expect($result)->toContain('Moved')
        ->and(Storage::disk('test-disk')->exists('a.txt'))->toBeFalse()
        ->and(Storage::disk('test-disk')->get('b.txt'))->toBe('data');
});

it('copies a file', function () {
    Storage::disk('test-disk')->put('original.txt', 'data');

    $result = dm(['operation' => 'copy', 'disk' => 'test-disk', 'path' => 'original.txt', 'destination' => 'clone.txt']);

    expect($result)->toContain('Copied')
        ->and(Storage::disk('test-disk')->get('original.txt'))->toBe('data')
        ->and(Storage::disk('test-disk')->get('clone.txt'))->toBe('data');
});

it('checks file existence', function () {
    Storage::disk('test-disk')->put('here.txt', 'yes');

    expect(dm(['operation' => 'exists', 'disk' => 'test-disk', 'path' => 'here.txt']))->toContain('exists')
        ->and(dm(['operation' => 'exists', 'disk' => 'test-disk', 'path' => 'nope.txt']))->toContain('does not exist');
});

it('creates a directory', function () {
    $result = dm(['operation' => 'mkdir', 'disk' => 'test-disk', 'path' => 'new-dir']);

    expect($result)->toContain('Directory created')
        ->and(Storage::disk('test-disk')->directories('/'))->toContain('new-dir');
});

// — Name deduplication —

it('renames file when name is taken', function () {
    Storage::disk('test-disk')->put('notes.txt', 'original');

    $result = dm(['operation' => 'write', 'disk' => 'test-disk', 'path' => 'notes.txt', 'content' => 'new']);

    expect($result)->toContain("'notes.txt' was taken, created 'notes1.txt'")
        ->and(Storage::disk('test-disk')->get('notes.txt'))->toBe('original')
        ->and(Storage::disk('test-disk')->get('notes1.txt'))->toBe('new');
});

it('increments file number until unique', function () {
    Storage::disk('test-disk')->put('doc.txt', 'v0');
    Storage::disk('test-disk')->put('doc1.txt', 'v1');
    Storage::disk('test-disk')->put('doc2.txt', 'v2');

    $result = dm(['operation' => 'write', 'disk' => 'test-disk', 'path' => 'doc.txt', 'content' => 'v3']);

    expect($result)->toContain('doc3.txt')
        ->and(Storage::disk('test-disk')->get('doc3.txt'))->toBe('v3');
});

it('renames file without extension when name is taken', function () {
    Storage::disk('test-disk')->put('README', 'original');

    $result = dm(['operation' => 'write', 'disk' => 'test-disk', 'path' => 'README', 'content' => 'new']);

    expect($result)->toContain("'README' was taken, created 'README1'")
        ->and(Storage::disk('test-disk')->get('README1'))->toBe('new');
});

it('renames directory when name is taken', function () {
    Storage::disk('test-disk')->makeDirectory('photos');
    Storage::disk('test-disk')->put('photos/a.jpg', 'img');

    $result = dm(['operation' => 'mkdir', 'disk' => 'test-disk', 'path' => 'photos']);

    expect($result)->toContain("'photos' was taken, created 'photos1'")
        ->and(Storage::disk('test-disk')->directories('/'))->toContain('photos1');
});

it('writes without renaming when path is free', function () {
    $result = dm(['operation' => 'write', 'disk' => 'test-disk', 'path' => 'fresh.txt', 'content' => 'hello']);

    expect($result)->toBe('Written to fresh.txt.');
});

it('renames move destination when taken', function () {
    Storage::disk('test-disk')->put('src.txt', 'data');
    Storage::disk('test-disk')->put('dst.txt', 'existing');

    $result = dm(['operation' => 'move', 'disk' => 'test-disk', 'path' => 'src.txt', 'destination' => 'dst.txt']);

    expect($result)->toContain("'dst.txt' was taken, moved src.txt to 'dst1.txt'")
        ->and(Storage::disk('test-disk')->get('dst.txt'))->toBe('existing')
        ->and(Storage::disk('test-disk')->get('dst1.txt'))->toBe('data');
});

it('renames copy destination when taken', function () {
    Storage::disk('test-disk')->put('src.txt', 'data');
    Storage::disk('test-disk')->put('dst.txt', 'existing');

    $result = dm(['operation' => 'copy', 'disk' => 'test-disk', 'path' => 'src.txt', 'destination' => 'dst.txt']);

    expect($result)->toContain("'dst.txt' was taken, copied src.txt to 'dst1.txt'")
        ->and(Storage::disk('test-disk')->get('dst.txt'))->toBe('existing')
        ->and(Storage::disk('test-disk')->get('dst1.txt'))->toBe('data');
});

// — Security —

it('rejects unauthorized disks', function () {
    $result = dm(['operation' => 'list', 'disk' => 'secret', 'path' => '/']);

    expect($result)->toContain('not allowed');
});

it('rejects deleting a system directory', function () {
    $result = dm(['operation' => 'delete', 'disk' => 'test-disk', 'path' => 'attachments']);

    expect($result)->toContain('Cannot delete system directory');
});

it('rejects moving a system directory', function () {
    $result = dm(['operation' => 'move', 'disk' => 'test-disk', 'path' => 'attachments', 'destination' => 'renamed']);

    expect($result)->toContain('Cannot move system directory');
});

it('rejects deleting files inside a system directory', function () {
    $result = dm(['operation' => 'delete', 'disk' => 'test-disk', 'path' => 'attachments/file.txt']);

    expect($result)->toContain('Cannot delete system directory');
});

it('rejects moving files inside a system directory', function () {
    $result = dm(['operation' => 'move', 'disk' => 'test-disk', 'path' => 'attachments/file.txt', 'destination' => 'elsewhere.txt']);

    expect($result)->toContain('Cannot move system directory');
});

it('allows reading from a system directory', function () {
    Storage::disk('test-disk')->put('attachments/note.txt', 'hello');

    $result = dm(['operation' => 'read', 'disk' => 'test-disk', 'path' => 'attachments/note.txt']);

    expect($result)->toBe('hello');
});

it('allows listing a system directory', function () {
    Storage::disk('test-disk')->put('attachments/note.txt', 'hello');

    $result = dm(['operation' => 'list', 'disk' => 'test-disk', 'path' => 'attachments']);

    $entries = json_decode($result, true);
    $names = array_column($entries, 'name');
    expect($names)->toContain('attachments/note.txt');
});

it('rejects path traversal', function () {
    $result = dm(['operation' => 'read', 'disk' => 'test-disk', 'path' => '../etc/passwd']);

    expect($result)->toContain('Path traversal is not allowed');
});

it('rejects path traversal in destination', function () {
    Storage::disk('test-disk')->put('a.txt', 'data');

    $result = dm(['operation' => 'move', 'disk' => 'test-disk', 'path' => 'a.txt', 'destination' => '../../etc/evil']);

    expect($result)->toContain('Path traversal is not allowed');
});

// — Error handling —

it('returns error when reading a missing file', function () {
    $result = dm(['operation' => 'read', 'disk' => 'test-disk', 'path' => 'missing.txt']);

    expect($result)->toContain('File not found');
});

it('requires destination for move', function () {
    Storage::disk('test-disk')->put('a.txt', 'data');

    $result = dm(['operation' => 'move', 'disk' => 'test-disk', 'path' => 'a.txt']);

    expect($result)->toContain('"destination" parameter is required');
});

it('requires content for write', function () {
    $result = dm(['operation' => 'write', 'disk' => 'test-disk', 'path' => 'file.txt']);

    expect($result)->toContain('"content" parameter is required');
});

it('includes allowed disks in description', function () {
    $tool = new DiskManager;

    expect((string) $tool->description())->toContain('test-disk');
});

// — save_attachment —

it('copies an attachment to an allowed disk', function () {
    Storage::fake('attachments-disk');
    Config::set('laraclaw.attachments.disk', 'attachments-disk');
    Storage::disk('attachments-disk')->put('laraclaw/attachments/abc/photo.jpg', 'image-data');

    $result = dm([
        'operation' => 'save_attachment',
        'disk' => 'test-disk',
        'path' => 'laraclaw/attachments/abc/photo.jpg',
        'destination' => 'photos/photo.jpg',
    ]);

    expect($result)->toContain('Saved attachment to photos/photo.jpg')
        ->and(Storage::disk('test-disk')->get('photos/photo.jpg'))->toBe('image-data');
});

it('deduplicates destination when saving attachment', function () {
    Storage::fake('attachments-disk');
    Config::set('laraclaw.attachments.disk', 'attachments-disk');
    Storage::disk('attachments-disk')->put('laraclaw/attachments/abc/photo.jpg', 'image-data');
    Storage::disk('test-disk')->put('photo.jpg', 'existing');

    $result = dm([
        'operation' => 'save_attachment',
        'disk' => 'test-disk',
        'path' => 'laraclaw/attachments/abc/photo.jpg',
        'destination' => 'photo.jpg',
    ]);

    expect($result)->toContain("'photo.jpg' was taken, saved attachment to 'photo1.jpg'")
        ->and(Storage::disk('test-disk')->get('photo1.jpg'))->toBe('image-data');
});

it('returns error when attachment source is missing', function () {
    Storage::fake('attachments-disk');
    Config::set('laraclaw.attachments.disk', 'attachments-disk');

    $result = dm([
        'operation' => 'save_attachment',
        'disk' => 'test-disk',
        'path' => 'laraclaw/attachments/missing.jpg',
        'destination' => 'photo.jpg',
    ]);

    expect($result)->toContain('Attachment not found');
});

it('suggests ImageManager for binary files', function () {
    Storage::disk('test-disk')->put('photo.png', random_bytes(100));

    $result = dm(['operation' => 'read', 'disk' => 'test-disk', 'path' => 'photo.png']);

    expect($result)->toContain('binary file')
        ->and($result)->toContain('ImageManager');
});

it('requires destination for save_attachment', function () {
    $result = dm([
        'operation' => 'save_attachment',
        'disk' => 'test-disk',
        'path' => 'laraclaw/attachments/abc/photo.jpg',
    ]);

    expect($result)->toContain('"destination" parameter is required');
});
