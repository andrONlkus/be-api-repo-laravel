<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakeDmsDatabase;
use Tests\TestCase;

/**
 * Endpoint lama harus identik dengan versi CodeIgniter (tanpa autentikasi).
 */
class LegacyApiTest extends TestCase
{
    use FakeDmsDatabase, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDms();
        Carbon::setTestNow('2026-10-13 10:00:00');
    }

    private function upload(string $nim, string $school = 'SBE', ?array $files = null)
    {
        return $this->post('/api/document', [
            'nim' => $nim,
            'school' => $school,
            'doc_date' => '2026-10',
            'expired_date' => '2030-12-31',
            'exp_public' => '2030-12-31',
            'userfile' => $files ?? [UploadedFile::fake()->create('Ijazah.pdf', 10, 'application/pdf')],
        ]);
    }

    public function test_health_check(): void
    {
        $this->get('/api')->assertOk()->assertExactJson(['status' => 'S', 'code' => '200', 'message' => 'Test API Success!']);
    }

    public function test_get_document_not_found_and_empty_nim(): void
    {
        $this->get('/api/document?nim=TEST404')->assertNotFound()
            ->assertExactJson(['status' => 'E', 'code' => '400', 'Message' => 'Record is empty', 'data' => '']);
        $this->get('/api/document?nim=')->assertNotFound()
            ->assertExactJson(['status' => 'E', 'code' => '400', 'Message' => 'NIM is empty']);
    }

    public function test_upload_create_then_get_and_download(): void
    {
        $res = $this->upload('TEST0000001')->assertOk()->json();

        $this->assertSame('S', $res['status']);
        $this->assertSame('200', $res['code']);
        $this->assertSame('TEST0000001', $res['data']['NIM']);
        $this->assertIsInt($res['data']['id_doc']);
        $this->assertSame('26/10/AKD.001.001.001/1', $res['data']['KodeArsip']);

        $doc = $this->dms()->table('document')->where('id_doc', $res['data']['id_doc'])->first();
        $this->assertSame('Ijazah,Transkrip,TEST0000001', $doc->keyword);
        $this->assertSame('Ijazah dan Transkrip - TEST0000001', $doc->summary);
        $this->assertSame('1', $doc->public);
        $this->assertSame(12, (int) $doc->departement_owner);
        $this->assertSame(8, (int) $doc->tipefile);
        $this->assertSame('1075', $doc->id_category);

        $get = $this->get('/api/document?nim=TEST0000001')->assertOk()->json();
        $this->assertSame(['status', 'code', 'Message', 'data'], array_keys($get));
        $this->assertSame(['keyword', 'summary', 'ListOfFiles'], array_keys($get['data']));
        $this->assertMatchesRegularExpression(
            '#^http://localhost/index\.php/api/download_file/(DOC_2026-10\d+\.pdf)/'.$res['data']['id_doc'].'\|Ijazah;$#',
            $get['data']['ListOfFiles']
        );
        $this->get('/api/document/nim/TEST0000001')->assertOk()->assertJsonPath('data.keyword', 'Ijazah,Transkrip,TEST0000001');

        preg_match('#download_file/([^/]+)/#', $get['data']['ListOfFiles'], $m);
        $this->get('/api/download_file/'.$m[1].'/'.$res['data']['id_doc'])->assertOk()->assertDownload('Ijazah.pdf');
        $this->get('/api/download_file/DOC_tidak_ada.pdf/1')->assertNotFound()
            ->assertExactJson(['status' => 'E', 'code' => '400', 'Message' => 'file not found, please contact administrator.']);
    }

    public function test_upload_existing_nim_replaces_files(): void
    {
        $first = $this->upload('TEST0000002')->json('data');
        $oldFile = $this->dms()->table('files')->where('id_doc', $first['id_doc'])->value('filename');

        Carbon::setTestNow('2026-10-13 10:05:00');
        $second = $this->upload('TEST0000002', 'SBE', [UploadedFile::fake()->create('Transkrip.pdf', 10, 'application/pdf')])
            ->assertOk()->json('data');

        $this->assertSame((string) $first['id_doc'], $second['id_doc']); // string, sama seperti CodeIgniter
        $this->assertSame($first['KodeArsip'], $second['KodeArsip']);
        $this->assertSame(['Transkrip'], $this->dms()->table('files')->where('id_doc', $first['id_doc'])->pluck('realname')->all());
        Storage::disk('arsip')->assertMissing($oldFile);
        $this->assertSame('Update via API', $this->dms()->table('doc_log')->orderByDesc('id_log')->value('activity'));
    }

    public function test_reupload_existing_nim_without_school(): void
    {
        $first = $this->upload('TEST0000004', 'STEM')->json('data');

        // CodeIgniter tidak membaca school saat update, kategori tetap
        $this->upload('TEST0000004', '')->assertOk()->assertJsonPath('data.KodeArsip', $first['KodeArsip']);
        $this->assertSame('1076', $this->dms()->table('document')->where('id_doc', $first['id_doc'])->value('id_category'));

        $this->upload('TEST0000005', '')->assertStatus(400)
            ->assertExactJson(['status' => 'E', 'code' => '400', 'Message' => 'school must be SBE or STEM']);
    }

    public function test_rest_controller_url_variants(): void
    {
        $this->upload('TEST0000006');
        $health = ['status' => 'S', 'code' => '200', 'message' => 'Test API Success!'];

        foreach (['/api/index', '/api/index.json', '/api/index/format/json', '/api/format/json'] as $url) {
            $this->get($url)->assertOk()->assertExactJson($health);
        }
        foreach ([
            '/api/document.json?nim=TEST0000006',
            '/api/document/format/json?nim=TEST0000006',
            '/api/document/nim/TEST0000006.json',
            '/api/document/nim/TEST0000006/format/json',
            '/api/document?nim=TEST0000006&format=json',
        ] as $url) {
            $this->get($url)->assertOk()->assertJsonPath('data.keyword', 'Ijazah,Transkrip,TEST0000006');
        }
        $this->get('/api/document.json?nim=')->assertNotFound()->assertJsonPath('Message', 'NIM is empty');
    }

    public function test_upload_too_large_returns_wrong_format(): void
    {
        $this->dms()->table('departement')->where('id_departement', 12)->update(['upload_limit' => 1]);

        $this->upload('TEST0000003', 'STEM', [UploadedFile::fake()->create('besar.pdf', 1100, 'application/pdf')])
            ->assertOk()->assertExactJson(['wrong_format']);
        $this->assertSame(0, $this->dms()->table('document')->count());
    }
}
