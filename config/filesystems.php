<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | 차량 업로드 서류 디스크 (말소서류·수출신고서·B/L 문서)
    |--------------------------------------------------------------------------
    | 로컬은 'public'(storage/app/public), AWS 배포 시 's3' 로 전환해 인스턴스 용량 확보.
    | store·delete·url 모두 이 디스크를 사용 → env 한 줄로 전환.
    */
    'vehicle_docs_disk' => env('VEHICLE_DOCS_DISK', 'public'),

    /*
    | 연동 B v2 첨부 수신 — board 가 보낸 S3 키를 읽어올 "소스" 디스크.
    | 운영: 미설정 → vehicle_docs_disk(=s3 공유버킷)와 동일 → 서버사이드 복사(바이트 전송 X).
    | 로컬: 'board_inbound' 로 지정(+ BOARD_STORAGE_PATH) → board 로컬 폴더에서 읽어 교차복사.
    */
    'purchase_sync_inbound_disk' => env('PURCHASE_SYNC_INBOUND_DISK', env('VEHICLE_DOCS_DISK', 'public')),

    /*
    | DB 백업 원격 디스크 — 설정 시 db:backup 이 mysqldump 결과를 이 디스크에도 업로드.
    | 단일 인스턴스 운영 시 's3' 권장(인스턴스 유실에도 백업 보존). 빈 값이면 로컬만.
    | S3 보관주기는 버킷 lifecycle 규칙으로 관리(로컬 --keep 와 별개).
    */
    'db_backup_disk' => env('DB_BACKUP_DISK', ''),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        // 로컬 전용 — 연동 B 첨부 소스로 board 앱 폴더를 읽기 위한 브리지.
        // BOARD_STORAGE_PATH = board 의 storage/app/public (purchase-board/... 키가 그 아래).
        'board_inbound' => [
            'driver' => 'local',
            'root' => env('BOARD_STORAGE_PATH', storage_path('app/board-inbound')),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
