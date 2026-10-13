<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Ganti koneksi "dms" dengan SQLite in-memory berisi tabel DMS minimal
 * (struktur mengikuti db_dms), plus disk arsip palsu dan API key uji.
 */
trait FakeDmsDatabase
{
    protected string $apiKey = 'test-key';

    protected function setUpDms(): void
    {
        config()->set('database.connections.dms', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge('dms');
        config()->set('arsip.api_keys', [$this->apiKey => 'test-app']);
        Storage::fake('arsip');

        $s = Schema::connection('dms');
        $s->create('document', function (Blueprint $t) {
            $t->increments('id_doc');
            $t->string('kode_arsip', 55);
            $t->string('yearDate', 11);
            $t->string('id_category', 155);
            $t->text('keyword');
            $t->text('summary');
            $t->text('filename');
            $t->string('status', 1);
            $t->string('isABulk', 1);
            $t->string('created_by', 11);
            $t->dateTime('created_date');
            $t->date('active_until')->nullable();
            $t->dateTime('edited_date')->nullable();
            $t->integer('edited_by')->nullable();
            $t->integer('departement_owner');
            $t->integer('tipefile');
            $t->string('public', 1)->default('0');
            $t->string('published', 155)->nullable();
            $t->date('expired_public')->nullable();
            $t->string('link', 155)->nullable();
        });
        $s->create('files', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_doc')->nullable();
            $t->string('filename', 155)->nullable();
            $t->text('realname')->nullable();
        });
        $s->create('doc_log', function (Blueprint $t) {
            $t->increments('id_log');
            $t->integer('id_doc');
            $t->string('idusers', 15);
            $t->string('activity', 55);
            $t->dateTime('created_date');
            $t->integer('id_delete')->nullable();
        });
        $s->create('categories', function (Blueprint $t) {
            $t->increments('id_category');
            $t->string('code_category', 55)->default('');
            $t->string('name_category', 155);
            $t->text('description')->default('');
            $t->integer('id_parent')->default(0);
            $t->string('doc_status')->default('musnah');
            $t->string('status_category')->default('active');
            $t->string('code_full', 55);
            $t->integer('doc_life')->default(5);
            $t->integer('inactive')->nullable();
            $t->integer('level')->nullable();
        });
        $s->create('departement', function (Blueprint $t) {
            $t->increments('id_departement');
            $t->string('nama_departement', 155);
            $t->string('status_departement')->default('active');
            $t->integer('hod')->nullable();
            $t->integer('upload_limit')->default(20);
        });
        $s->create('tipefile', function (Blueprint $t) {
            $t->increments('id_tipefile');
            $t->string('tipefile', 155);
            $t->string('status_tipefile')->default('active');
        });
        $s->create('users', function (Blueprint $t) {
            $t->increments('idusers');
            $t->string('username', 45);
            $t->string('active', 1)->default('Y');
        });
        $s->create('category_acc', function (Blueprint $t) {
            $t->increments('id_access');
            $t->integer('id_category');
            $t->integer('id_dept');
            $t->string('access', 55);
        });

        $db = DB::connection('dms');
        $db->table('categories')->insert([
            ['id_category' => 1, 'name_category' => 'Penjaminan Mutu', 'code_full' => 'SPM', 'level' => 1, 'id_parent' => 0, 'doc_life' => 99, 'doc_status' => 'permanen', 'status_category' => 'active'],
            ['id_category' => 2, 'name_category' => 'Audit', 'code_full' => 'SPM.003', 'level' => 2, 'id_parent' => 1, 'doc_life' => 99, 'doc_status' => 'permanen', 'status_category' => 'active'],
            ['id_category' => 3, 'name_category' => 'Laporan', 'code_full' => 'SPM.003.013', 'level' => 3, 'id_parent' => 2, 'doc_life' => 5, 'doc_status' => 'permanen', 'status_category' => 'active'],
            ['id_category' => 4, 'name_category' => 'Nonaktif', 'code_full' => 'SPM.009', 'level' => 2, 'id_parent' => 1, 'doc_life' => 5, 'doc_status' => 'musnah', 'status_category' => 'deactive'],
            ['id_category' => 1075, 'name_category' => 'Ijazah SBE', 'code_full' => 'AKD.001.001.001', 'level' => 4, 'id_parent' => 0, 'doc_life' => 99, 'doc_status' => 'permanen', 'status_category' => 'active'],
            ['id_category' => 1076, 'name_category' => 'Ijazah STEM', 'code_full' => 'AKD.001.001.002', 'level' => 4, 'id_parent' => 0, 'doc_life' => 99, 'doc_status' => 'permanen', 'status_category' => 'active'],
        ]);
        $db->table('departement')->insert([
            ['id_departement' => 12, 'nama_departement' => 'Admin Univ', 'upload_limit' => 20],
            ['id_departement' => 30, 'nama_departement' => 'BPM', 'upload_limit' => 1],
        ]);
        $db->table('tipefile')->insert([['id_tipefile' => 8, 'tipefile' => 'Arsip Adm Mahasiswa']]);
        $db->table('users')->insert([['idusers' => 819, 'username' => 'repository'], ['idusers' => 900, 'username' => 'staff']]);
        $db->table('category_acc')->insert([['id_category' => 1, 'id_dept' => 30, 'access' => '3'], ['id_category' => 1, 'id_dept' => 12, 'access' => '1']]);
    }

    protected function dms()
    {
        return DB::connection('dms');
    }
}
