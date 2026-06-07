<?php

use Lily\Http\UploadedFile;
use Lily\Http\ChunkedUploader;
use Lily\Http\Request;

echo "--- Testing UploadedFile ---\n";
$file = new UploadedFile('test.jpg', 'image/jpeg', '/tmp/fake', UPLOAD_ERR_OK, 1024);
assertEquals('test.jpg', $file->getOriginalName());
assertEquals('image/jpeg', $file->getMimeType());
assertEquals('jpg', $file->getExtension());
assertEquals(1024, $file->getSize());
assertEquals(UPLOAD_ERR_OK, $file->getError());
assertEquals('There is no error, the file uploaded with success.', $file->getErrorMessage());

// Since the file is fake and wasn't uploaded via HTTP POST, isValid() should securely fail.
assertFalse($file->isValid(), "Fake file should securely fail isValid() via is_uploaded_file");
assertFalse($file->store('/tmp/dest'), "store() should fail on invalid file");

$errorFile = new UploadedFile('test.jpg', 'image/jpeg', '', UPLOAD_ERR_INI_SIZE, 0);
assertFalse($errorFile->isValid());
assertEquals('The uploaded file exceeds the upload_max_filesize directive in php.ini.', $errorFile->getErrorMessage());

echo "--- Testing Request File Parsing ---\n";
$filesArray = [
    'avatar' => [
        'name' => 'avatar.png',
        'type' => 'image/png',
        'tmp_name' => '/tmp/phpYzdqkD',
        'error' => 0,
        'size' => 123
    ],
    'documents' => [
        'name' => ['doc1.pdf', 'doc2.pdf'],
        'type' => ['application/pdf', 'application/pdf'],
        'tmp_name' => ['/tmp/php1', '/tmp/php2'],
        'error' => [0, 0],
        'size' => [100, 200]
    ]
];

$request = new Request([], [], [], $filesArray, []);
$avatar = $request->file('avatar');
assertTrue($avatar instanceof UploadedFile);
assertEquals('avatar.png', $avatar->getOriginalName());

$docs = $request->file('documents');
assertTrue(is_array($docs) && count($docs) === 2);
assertTrue($docs[0] instanceof UploadedFile);
assertEquals('doc1.pdf', $docs[0]->getOriginalName());
assertEquals('doc2.pdf', $docs[1]->getOriginalName());

echo "--- Testing ChunkedUploader ---\n";

$tempDir = __DIR__ . '/test_uploads';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

// Create fake chunks
$chunk1Data = "Chunk1";
$chunk2Data = "Chunk2";
$totalSize = strlen($chunk1Data) + strlen($chunk2Data);

$chunk1File = tempnam($tempDir, 'chk1');
$chunk2File = tempnam($tempDir, 'chk2');
file_put_contents($chunk1File, $chunk1Data);
file_put_contents($chunk2File, $chunk2Data);

$uploader = new ChunkedUploader($tempDir);

// Simulate first chunk
$request1 = new Request([], [], ['HTTP_CONTENT_RANGE' => 'bytes 0-5/12'], [
    'chunk' => [
        'name' => 'bigfile.txt',
        'type' => 'text/plain',
        'tmp_name' => $chunk1File,
        'error' => UPLOAD_ERR_OK,
        'size' => 6
    ]
], []);

$res1 = $uploader->handle($request1, 'chunk');
assertTrue($res1 === null, "First chunk should return null");

// Simulate second chunk
$request2 = new Request([], [], ['HTTP_CONTENT_RANGE' => 'bytes 6-11/12'], [
    'chunk' => [
        'name' => 'bigfile.txt',
        'type' => 'text/plain',
        'tmp_name' => $chunk2File,
        'error' => UPLOAD_ERR_OK,
        'size' => 6
    ]
], []);

$res2 = $uploader->handle($request2, 'chunk');
assertTrue($res2 instanceof UploadedFile, "Final chunk should return UploadedFile");

// Verify content of assembled file
$assembledContent = file_get_contents($res2->getTmpName());
assertEquals("Chunk1Chunk2", $assembledContent, "Chunks should be assembled correctly in order");
assertEquals(12, $res2->getSize());

// Clean up
unlink($chunk1File);
unlink($chunk2File);
unlink($res2->getTmpName());
rmdir($tempDir);
