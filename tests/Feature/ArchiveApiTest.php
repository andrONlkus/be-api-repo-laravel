<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakeDmsDatabase;
use Tests\TestCase;

class ArchiveApiTest extends TestCase
{
    use FakeDmsDatabase, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDms();
        Carbon::setTestNow('2026-10-13 10:00:00');
    }

    private function headers(): array
    {
        return ['X-API-KEY' => $this->apiKey, 'Accept' => 'application/json'];
    }

    private function createPayload(array $override = []): array
    {
        return array_merge([
            'id_category' => 3,
            'departement_owner' => 30,
            'tipefile' => 8,
            'doc_date' => '2026-10',
            'keyword' => 'TESTAPIARSIP,uji',
            'summary' => 'Uji API arsip',
            'userfile' => [
                UploadedFile::fake()->create('Laporan Uji.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('Lampiran.pdf', 10, 'application/pdf'),
            ],
        ], $override);
    }

    private function createArchive(array $override = []): array
    {
        return $this->post('/api/archive', $this->createPayload($override), $this->headers())
            ->assertCreated()->json('data');
    }

    public function test_request_without_or_with_wrong_key_is_rejected(): void
    {
        $this->getJson('/api/archive')->assertUnauthorized()->assertJsonPath('status', 'E');
        $this->getJson('/api/archive', ['X-API-KEY' => 'salah'])->assertUnauthorized()->assertJsonPath('message', 'API key tidak valid.');
    }

    public function test_sanctum_token_with_abilities(): void
    {
        $client = ApiClient::create(['name' => 'siakad']);
        $readOnly = $client->createToken('t', ['archive:read'])->plainTextToken;
        $full = $client->createToken('t', ['archive:read', 'archive:write'])->plainTextToken;

        $this->withToken($readOnly)->getJson('/api/master/tipefiles')->assertOk()->assertJsonPath('status', 'S');
        $this->withToken($readOnly)->postJson('/api/archive', [])->assertForbidden();
        $this->app['auth']->forgetGuards(); // guard menyimpan user antar request dalam satu test
        $this->withToken($full)->postJson('/api/archive', [])->assertUnprocessable();

        $client->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($full)->getJson('/api/master/tipefiles')->assertUnauthorized();
    }

    public function test_master_data(): void
    {
        $this->getJson('/api/master/categories?level=3', $this->headers())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code_full', 'SPM.003.013')->assertJsonPath('data.0.can_upload', true);
        $this->getJson('/api/master/categories?level=9', $this->headers())->assertUnprocessable()->assertJsonStructure(['errors' => ['level']]);
        $this->getJson('/api/master/departements', $this->headers())->assertOk()->assertJsonPath('data.0.nama_departement', 'Admin Univ');
        $this->getJson('/api/master/tipefiles', $this->headers())->assertOk()->assertJsonPath('data.0.id_tipefile', 8);
    }

    public function test_create_validates_fields(): void
    {
        $this->post('/api/archive', [], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Validation Error')
            ->assertJsonStructure(['errors' => ['id_category', 'departement_owner', 'tipefile', 'doc_date', 'keyword', 'summary', 'userfile']]);

        // level 1, kategori nonaktif, departemen tidak ada, format salah
        foreach ([['id_category' => 1], ['id_category' => 4]] as $bad) {
            $this->post('/api/archive', $this->createPayload($bad), $this->headers())
                ->assertUnprocessable()->assertJsonStructure(['errors' => ['id_category']]);
        }
        $this->post('/api/archive', $this->createPayload(['departement_owner' => 999, 'doc_date' => '2026-13', 'public' => '5', 'created_by' => 12345]), $this->headers())
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['departement_owner', 'doc_date', 'public', 'created_by']]);
    }

    public function test_create_rejects_disallowed_file_and_oversized_file(): void
    {
        $this->post('/api/archive', $this->createPayload([
            'userfile' => [UploadedFile::fake()->create('virus.exe', 1, 'application/x-msdownload')],
        ]), $this->headers())->assertUnprocessable()->assertJsonStructure(['errors' => ['userfile.0']]);

        // Departemen 30 upload_limit 1 MB
        $this->post('/api/archive', $this->createPayload([
            'userfile' => [UploadedFile::fake()->create('besar.pdf', 1100, 'application/pdf')],
        ]), $this->headers())->assertUnprocessable()->assertJsonStructure(['errors' => ['userfile.0']]);

        $this->assertSame([], Storage::disk('arsip')->allFiles());
        $this->assertSame(0, $this->dms()->table('document')->count());
    }

    public function test_create_with_access_validation(): void
    {
        // Dept 12 hanya punya access 1 (view) di SPM
        $this->post('/api/archive', $this->createPayload(['departement_owner' => 12, 'validate_access' => '1']), $this->headers())
            ->assertForbidden()->assertJsonPath('status', 'E');
        $this->assertSame([], Storage::disk('arsip')->allFiles());

        // Dept 30 punya access 3 di SPM (induk dari SPM.003.013)
        $this->post('/api/archive', $this->createPayload(['validate_access' => '1']), $this->headers())->assertCreated();
    }

    public function test_create_success(): void
    {
        $this->dms()->table('document')->insert([
            'kode_arsip' => '26/10/SPM.003.013/2', 'yearDate' => '2026-10', 'id_category' => '3', 'keyword' => 'x', 'summary' => 'x',
            'filename' => '', 'status' => 'A', 'isABulk' => 'N', 'created_by' => '819', 'created_date' => '2026-10-01 00:00:00',
            'departement_owner' => 30, 'tipefile' => 8,
        ]);
        // Kode sub-kategori tidak boleh ikut terhitung untuk kategori induk
        $this->dms()->table('document')->insert([
            'kode_arsip' => '26/10/SPM.003.013.001/9', 'yearDate' => '2026-10', 'id_category' => '3', 'keyword' => 'x', 'summary' => 'x',
            'filename' => '', 'status' => 'A', 'isABulk' => 'N', 'created_by' => '819', 'created_date' => '2026-10-01 00:00:00',
            'departement_owner' => 30, 'tipefile' => 8,
        ]);

        $data = $this->createArchive();

        $this->assertSame('26/10/SPM.003.013/3', $data['kode_arsip']);
        $this->assertCount(2, $data['files']);
        $this->assertSame('Laporan Uji', $data['files'][0]['realname']);
        $this->assertMatchesRegularExpression('#^DOC_2026-10\d+0\.pdf$#', $data['files'][0]['filename']);
        Storage::disk('arsip')->assertExists($data['files'][0]['filename']);

        $doc = $this->dms()->table('document')->where('id_doc', $data['id_doc'])->first();
        $this->assertSame('2031-10-13', $doc->active_until); // doc_life 5 tahun
        $this->assertSame('0', $doc->public);
        $this->assertSame('819', $doc->created_by);
        $this->assertSame(implode(',', array_column($data['files'], 'filename')), $doc->filename);
        $this->assertSame(2, $this->dms()->table('files')->where('id_doc', $data['id_doc'])->count());
        $this->assertSame('Create via API', $this->dms()->table('doc_log')->where('id_doc', $data['id_doc'])->value('activity'));
    }

    public function test_detail_and_search(): void
    {
        $data = $this->createArchive();

        $this->getJson('/api/archive/'.$data['id_doc'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.category.code_full', 'SPM.003.013')
            ->assertJsonPath('data.departement.nama_departement', 'BPM')
            ->assertJsonCount(2, 'data.files');
        $this->getJson('/api/archive/999', $this->headers())->assertNotFound()->assertJsonPath('message', 'Arsip tidak ditemukan.');

        $this->getJson('/api/archive?q=TESTAPIARSIP', $this->headers())
            ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.id_doc', $data['id_doc']);
        $this->getJson('/api/archive?id_category=1&include_sub=1', $this->headers())->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/archive?id_category=1', $this->headers())->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/archive?year=2026&departement_owner=30&public=0&limit=1', $this->headers())
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.meta.last_page', 1);
        $this->getJson('/api/archive?q=tidakada', $this->headers())->assertJsonPath('data.meta.total', 0);
        // q juga mencari realname file
        $this->getJson('/api/archive?q='.urlencode('Laporan Uji'), $this->headers())
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.id_doc', $data['id_doc']);
        $this->getJson('/api/archive?limit=500&sort=x&doc_date=2026', $this->headers())
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['limit', 'sort', 'doc_date']]);
    }

    public function test_download(): void
    {
        $data = $this->createArchive();
        $file = $data['files'][0]['filename'];

        $this->get('/api/archive/'.$data['id_doc'].'/file/'.$file, $this->headers())
            ->assertOk()->assertDownload('Laporan Uji.pdf');
        $this->get('/api/archive/'.$data['id_doc'].'/file/'.$file.'?api_key='.$this->apiKey)->assertOk();
        $this->getJson('/api/archive/'.$data['id_doc'].'/file/DOC_lain.pdf', $this->headers())->assertNotFound();
        // ?api_key= hanya untuk download
        $this->getJson('/api/archive/'.$data['id_doc'].'?api_key='.$this->apiKey)->assertUnauthorized();

        $this->assertSame(2, $this->dms()->table('doc_log')->where('activity', 'Download via API')->count());
    }

    public function test_update_metadata_and_files(): void
    {
        $data = $this->createArchive();
        [$first, $second] = array_column($data['files'], 'filename');
        $url = '/api/archive/'.$data['id_doc'];

        $this->post($url, [], $this->headers())->assertUnprocessable()->assertJsonStructure(['errors' => ['data']]);

        $this->post($url, ['summary' => 'Diubah', 'public' => '1', 'edited_by' => 900], $this->headers())
            ->assertOk()->assertJsonPath('data.summary', 'Diubah')->assertJsonPath('data.public', '1')
            ->assertJsonPath('data.edited_by', 900)->assertJsonPath('data.kode_arsip', $data['kode_arsip']);

        // append
        $res = $this->post($url, ['userfile' => [UploadedFile::fake()->create('Tambahan.pdf', 5, 'application/pdf')]], $this->headers())
            ->assertOk()->json('data.files');
        $this->assertCount(3, $res);

        // hapus file tertentu
        $this->post($url, ['delete_files' => ['DOC_bukan_milik.pdf']], $this->headers())
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['delete_files']]);
        $this->post($url, ['delete_files' => [$second]], $this->headers())->assertOk()->assertJsonCount(2, 'data.files');
        Storage::disk('arsip')->assertMissing($second);

        // replace
        $this->post($url, ['file_mode' => 'replace'], $this->headers())->assertUnprocessable();
        $files = $this->post($url, ['file_mode' => 'replace', 'userfile' => [UploadedFile::fake()->create('Baru.pdf', 5, 'application/pdf')]], $this->headers())
            ->assertOk()->json('data.files');
        $this->assertSame(['Baru'], array_column($files, 'realname'));
        Storage::disk('arsip')->assertMissing($first);
        $this->assertSame($files[0]['filename'], $this->dms()->table('document')->where('id_doc', $data['id_doc'])->value('filename'));
        $this->assertSame(1, $this->dms()->table('files')->where('id_doc', $data['id_doc'])->count());

        // tidak boleh menghapus file terakhir
        $this->post($url, ['delete_files' => [$files[0]['filename']]], $this->headers())->assertUnprocessable();
    }

    public function test_update_keeps_working_when_old_physical_file_is_missing(): void
    {
        $data = $this->createArchive();
        Storage::disk('arsip')->delete($data['files'][0]['filename']);

        $this->post('/api/archive/'.$data['id_doc'], [
            'file_mode' => 'replace',
            'userfile' => [UploadedFile::fake()->create('Baru.pdf', 5, 'application/pdf')],
        ], $this->headers())->assertOk()->assertJsonCount(1, 'data.files');
    }

    public function test_update_ignores_empty_fields(): void
    {
        $doc = $this->createArchive(['public' => '1', 'expired_public' => '2030-12-31', 'link' => 'https://contoh.id']);

        // Frontend mengirim seluruh form: field kosong tidak boleh menimpa data dengan NULL
        $this->post('/api/archive/'.$doc['id_doc'], [
            'summary' => 'Diubah',
            'keyword' => '', 'id_category' => '', 'tipefile' => '', 'doc_date' => '',
            'public' => '', 'expired_public' => '', 'active_until' => '', 'link' => '',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary', 'Diubah')
            ->assertJsonPath('data.keyword', 'TESTAPIARSIP,uji')
            ->assertJsonPath('data.category.id_category', 3)
            ->assertJsonPath('data.public', '1')
            ->assertJsonPath('data.expired_public', '2030-12-31')
            ->assertJsonPath('data.link', 'https://contoh.id');
        $this->assertNotNull($this->dms()->table('document')->where('id_doc', $doc['id_doc'])->value('active_until'));
    }

    public function test_single_userfile_and_single_delete_files_are_accepted(): void
    {
        $doc = $this->createArchive(['userfile' => UploadedFile::fake()->create('Satu.pdf', 10, 'application/pdf')]);
        $this->assertCount(1, $doc['files']);

        $this->post('/api/archive/'.$doc['id_doc'], [
            'userfile' => UploadedFile::fake()->create('Dua.pdf', 10, 'application/pdf'),
            'delete_files' => $doc['files'][0]['filename'],
        ], $this->headers())->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.realname', 'Dua');

        $this->post('/api/archive/'.$doc['id_doc'], ['summary' => 'x', 'delete_files' => ['', ' ']], $this->headers())
            ->assertOk()->assertJsonCount(1, 'data.files');
    }

    public function test_search_normalizes_order_and_include_sub(): void
    {
        $this->createArchive();

        $this->getJson('/api/archive?order=DESC&include_sub=true&id_category=3', $this->headers())
            ->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/archive?departement_owner=0&tipefile=-1', $this->headers())
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['departement_owner', 'tipefile']]);
        $this->getJson('/api/master/categories?parent=0', $this->headers())
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['parent']]);
        $this->getJson('/api/master/categories?q=%25', $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }
}
