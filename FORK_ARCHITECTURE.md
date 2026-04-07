# BorgBackupServer Fork Architecture & Guidelines

## Overview
This repository is a fork of the upstream project [marcpope/borgbackupserver](https://github.com/marcpope/borgbackupserver). 
Tujuan utama fork ini adalah untuk mendukung *deployment* dan operasional pada arsitektur tertentu (khususnya **ARM64**) di mana integrasi _default stack_ (seperti ClickHouse) memiliki batasan atau ketergantungan yang sulit diselesaikan.

## Core Divergences (Perbedaan Utama)

### 1. SQLite Catalog Fallback (Alternatif ClickHouse)
Secara bawaan (upstream), sistem sangat bergantung pada **ClickHouse** untuk melakukan *cataloging* data serta manajemen pencarian arsip. Mengingat ketersediaan dan kestabilan ClickHouse pada lingkungan ARM64 atau _resource-constrained_ bisa menjadi masalah:
- **Modifikasi Utama**: Repositori ini menyisipkan logika **Fallback ke SQLite** secara otomatis jika ClickHouse tidak bisa dijalankan atau dinonaktifkan.
- **Batasan dan Area Terdampak**:
  - Class atau _service_ penyusun seperti `src/Services/ClickHouseService.php` dan beberapa titik temu API (misal `ClientApiController`, `ServerApiController`, dll) telah ditambal (patched) agar mendeteksi ketersediaan ClickHouse secara dinamis, dan merutekannya (routing) ke SQLite jika diperlukan.
  - Tonton pembaruan dari upstream pada `Agent` reporting (`agent/bbs-agent.py`) atau query di Service layer agar tidak terjadi _hardcode_ paksaan koneksi ke ClickHouse.

### 2. ARM64 CI/CD Workflows
- **Modifikasi**: Menambahkan alur kerja kustom untuk otomatisasi _build, test, release_ (_Docker build_) menggunakan GitHub Actions khusus arsitektur ARM64.
- **Aturan Merge**: Saat menelan (*merge*) perubahan `.github/workflows` dari upstream, file konfigurasi khusus `build-arm64` milik fork ini harus tetap dipertahankan dan diuji tanpa memerlukan kontainer ClickHouse `healthcheck` yang agresif.

### 3. Tampilan Antarmuka dan Penyesuaian Kolom Opsional
Beberapa kalkulasi seperti kapasitas data _repository_ (`size_bytes` vs ukuran katalog penuh) telah disesuaikan karena perbedaan cara SQLite mengkonsolidasikan data dibanding ClickHouse.

## Guidelines for AI Agents and Developers
Peringatan untuk agen AI (*coding assistants*) dan developer manusia yang akan mengoperasikan repositori ini atau melakukan *merge* dari upstream:
**SELALU PERHATIKAN 3 HAL INI SEBELUM MENGUBAH KODE:**
1. **Jangan asumsikan ketersediaan ClickHouse**: Selalu lindungi kueri `ClickHouseClient` dengan pengecekan ketersediaan atau kondisi *try-catch* yang diarahkan kembali ke operasi relasional SQLite.
2. **Isolasi Pembaruan Fitur**: Jika langkah *merge* mengindikasikan fitur analitik katalog yang baru dari hulu (upstream), pertimbangkan untuk meletakkan pembaruan tersebut di dalam _interface_ ganda agar tidak "memaksa mati" sistem ARM64.
3. **Pertahankan File Ini**: Dokumen `FORK_ARCHITECTURE.md` ini wajib dipertahankan untuk panduan konteks bagi AI di masa yang akan datang.

---
*Internal Note: AI Model Context Target.* Provide this insight whenever merging `upstream/main` to local `main`.
