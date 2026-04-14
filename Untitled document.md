# **1\. Bài toán mà kiến trúc này giải quyết**

Hiện tại, đa số hệ thống import hay gặp 4 vấn đề:

## **Vấn đề 1: mỗi loại import tự làm một flow riêng**

Ví dụ:

* import cost center có flow riêng  
* import payroll có flow riêng  
* import employee có flow riêng

Hệ quả:

* code lặp  
* bug fix một nơi phải sửa nhiều nơi  
* response preview không đồng nhất  
* thêm loại import mới rất tốn công

---

## **Vấn đề 2: preview và commit dùng logic khác nhau**

Ví dụ:

* preview validate kiểu A  
* commit validate kiểu B

Hệ quả:

* preview báo OK nhưng import thật fail  
* user mất niềm tin vào tính năng preview

---

## **Vấn đề 3: business logic bị nhét vào controller/service chung**

Hệ quả:

* controller phình to  
* khó test  
* khó reuse giữa các service

---

## **Vấn đề 4: khó dùng chung giữa nhiều microservice**

Nếu mỗi service tự build import theo cách riêng:

* mỗi nơi một API  
* mỗi nơi một cách lưu session  
* mỗi nơi một kiểu summary/error  
* không có platform import thống nhất

---

# **2\. Tư duy thiết kế cốt lõi**

Thiết kế này tách import thành **2 phần lớn**:

## **Phần A — Framework import dùng chung**

Đây là phần package `import-kit` chịu trách nhiệm:

* nhận file  
* lưu file tạm  
* tạo preview session  
* đọc file  
* chạy pipeline parse/validate/map/commit  
* trả response preview chuẩn  
* submit queue job  
* theo dõi trạng thái import

Phần này **không biết business cụ thể**.

---

## **Phần B — Module import của từng loại dữ liệu**

Mỗi loại import chỉ cần cung cấp:

* `kind`  
* danh sách header bắt buộc  
* cách parse row  
* cách validate row  
* cách map row thành command  
* cách commit command vào DB

Ví dụ:

* `cost_center_entry`  
* `payroll_metric`  
* `employee_account`  
* `merchant_balance`

Như vậy:

* package chung không cần sửa khi thêm loại import mới  
* chỉ cần “cắm” thêm module mới

---

# **3\. Kiến trúc tổng thể**

Ta chia làm 3 lớp:

## **3.1 Infrastructure layer**

Phần kỹ thuật dùng chung, không chứa business logic.

Bao gồm:

* `FileStore`  
* `PreviewSessionStore`  
* `SourceReader`  
* `JobRepository`  
* `QueueDispatcher`

Đây là lớp xử lý:

* lưu file ở local/S3  
* lưu session preview trong DB/cache  
* mở file CSV/XLSX  
* dispatch queue  
* tracking trạng thái job

---

## **3.2 Application layer**

Đây là **engine chung** của package.

Bao gồm:

* `ImportPipeline`  
* `ImportPreviewService`  
* `ImportCommitService`  
* `ImportRegistry`  
* `ImportJobStatusService`

Nhiệm vụ:

* điều phối flow import  
* gọi các thành phần plugin  
* chuẩn hóa output

---

## **3.3 Domain layer**

Đây là phần mỗi service tự viết.

Bao gồm các module import như:

* `CostCenterEntryImportModule`  
* `PayrollMetricImportModule`

Mỗi module tự định nghĩa:

* rule nghiệp vụ  
* duplicate policy  
* upsert/create/update  
* persist vào DB

---

# **4\. Khái niệm quan trọng nhất: ImportModule**

Đây là extension point trung tâm.

Thay vì hardcode từng loại import vào engine, engine chỉ làm việc với một interface chung.

Ví dụ khái niệm:

interface ImportModuleInterface  
{  
   public function kind(): string;

   public function requiredHeaders(): array;

   public function optionalHeaders(): array;

   public function makeRowParser(): RowParserInterface;

   public function makeRowValidator(): RowValidatorInterface;

   public function makeRowMapper(): RowMapperInterface;

   public function makeRowCommitter(): RowCommitterInterface;  
}

## **Ý nghĩa**

Mỗi loại import là một “plugin” trả lời các câu hỏi:

* loại import này tên gì?  
* file cần những cột nào?  
* mỗi dòng được parse như thế nào?  
* validate ra sao?  
* map sang command gì?  
* commit vào database như thế nào?

---

# **5\. Tại sao phải tách parser / validator / mapper / committer?**

Nhiều đội dev hay gom hết vào một class kiểu `ImportDefinition`, nhưng về lâu dài sẽ rất khó maintain.

Thiết kế tốt hơn là tách rõ 4 bước:

## **5.1 Parser**

Nhiệm vụ:

* đọc raw cells  
* trim string  
* convert number/date  
* normalize null/empty  
* map header sang field

Ví dụ:

* `" CC001 "` → `"CC001"`  
* `"10,000"` → `10000`  
* `" "` → `null`

Parser chỉ xử lý **data format**, chưa xử lý business rule.

---

## **5.2 Validator**

Nhiệm vụ:

* field bắt buộc  
* format đúng không  
* category có tồn tại không  
* period có hợp lệ không  
* cost center có thuộc workspace không

Validator trả về:

* OK  
* hoặc danh sách lỗi theo field

Validator xử lý **business validation**.

---

## **5.3 Mapper**

Nhiệm vụ:

* chuyển row đã validated thành command/domain DTO

Ví dụ:

* từ row Excel  
* map sang `UpsertCostCenterEntryCommand`

Lý do cần mapper:

* tách dữ liệu import khỏi domain model  
* tránh importer phụ thuộc trực tiếp Excel format

---

## **5.4 Committer**

Nhiệm vụ:

* nhận command  
* ghi database / gọi domain service  
* áp dụng policy create/update/skip

Ví dụ:

* `updateOrCreate`  
* `insert`  
* `skip duplicate`  
* `fail on duplicate`

Committer là chỗ duy nhất được phép tạo side effect.

---

# **6\. Flow chuẩn của toàn bộ import**

Đây là flow mà package phải giữ cố định.

## **6.1 Phase 1 — Tạo session upload**

API:  
 `POST /imports/{kind}/sessions`

Client upload file.

Server làm:

1. validate file  
2. lưu file tạm qua `FileStore`  
3. tạo `PreviewSession`  
4. trả về `session_id`

Response:

{  
 "session\_id": "uuid",  
 "expires\_at": "2026-04-13T10:00:00Z"  
}

## **Mục tiêu**

User không cần upload lại file mỗi lần preview hay chuyển trang.

---

## **6.2 Phase 2 — Preview**

API:  
 `GET /imports/{kind}/preview?session_id=...`

Server làm:

1. lấy session  
2. mở file từ `file_handle`  
3. parse header  
4. check required headers  
5. iterate rows  
6. parse từng row  
7. validate từng row  
8. map preview result  
9. trả summary \+ rows

Quan trọng:

* **preview không ghi DB**  
* preview và commit dùng cùng parser/validator/mapper

---

## **6.3 Phase 3 — Submit**

API:  
 `POST /imports/{kind}/jobs`

Server làm:

1. validate permission commit  
2. check session hợp lệ  
3. tạo `ImportJob`  
4. dispatch queue job  
5. trả `job_id`

---

## **6.4 Phase 4 — Background commit**

Worker xử lý:

1. đọc lại session/file  
2. iterate toàn bộ file  
3. parse  
4. validate  
5. map command  
6. commit từng row/chunk  
7. cập nhật progress  
8. lưu summary cuối

---

## **6.5 Phase 5 — Query trạng thái**

API:  
 `GET /imports/jobs/{job_id}`

Trả:

* pending / processing / completed / failed  
* processed\_rows  
* ok\_rows  
* error\_rows  
* sample errors

---

# **7\. Tại sao preview và commit phải dùng cùng engine?**

Đây là một nguyên tắc rất quan trọng.

Nếu preview dùng logic riêng, commit dùng logic riêng:

* rule dễ lệch  
* bug khó phát hiện  
* người dùng thấy preview “không đáng tin”

Thiết kế đúng là:

* cùng `SourceReader`  
* cùng `RowParser`  
* cùng `RowValidator`  
* cùng `RowMapper`

Khác nhau duy nhất là **terminal step**:

## **Preview terminal**

* build `PreviewRowResult`  
* aggregate summary  
* không side effect

## **Commit terminal**

* gọi `RowCommitter`  
* cập nhật progress  
* lưu error logs

Tức là:

engine giống nhau, điểm kết thúc khác nhau.

---

# **8\. Các thành phần chính trong package**

Dưới đây là những class/layer đội dev nên triển khai.

---

## **8.1 FileStore**

Chịu trách nhiệm lưu file tạm.

Interface:

interface FileStoreInterface  
{  
   public function putUploadedFile($file, array $meta \= \[\]): StoredFile;

   public function exists(string $fileHandle): bool;

   public function openStream(string $fileHandle);

   public function delete(string $fileHandle): void;  
}

### **Ý nghĩa**

* package không được phụ thuộc trực tiếp vào local path  
* file có thể nằm local disk, S3, hoặc storage khác  
* client chỉ thấy `session_id`, không thấy path thực

---

## **8.2 PreviewSessionStore**

Lưu metadata của session preview.

Thông tin cần lưu:

* `session_id`  
* `kind`  
* `file_handle`  
* `tenant_id`  
* `workspace_id`  
* `context`  
* `expires_at`  
* `status`

Ví dụ context:

{  
 "period": "2026-04",  
 "workspace\_id": 123  
}

### **Vai trò**

* gắn file với ngữ cảnh import  
* reuse file giữa nhiều lần preview  
* bảo vệ tenant/workspace boundary

---

## **8.3 SourceReader**

Abstraction để đọc file.

Không nên hardcode package chỉ đọc Excel.  
 Nên có interface:

interface SourceReaderInterface  
{  
   public function open(StoredFile $file): void;

   public function headers(): array;

   public function rows(RowWindow $window \= null): iterable;

   public function close(): void;  
}

### **Tại sao cần abstraction?**

Để sau này hỗ trợ:

* CSV  
* XLSX  
* TSV

mà không phải sửa pipeline.

---

## **8.4 ImportRegistry**

Dùng để lookup module theo `kind`.

interface ImportRegistryInterface  
{  
   public function get(string $kind): ImportModuleInterface;  
}

### **Ý nghĩa**

Controller không cần biết `cost_center_entry` map vào class nào.  
 Nó chỉ cần:

* nhận `kind`  
* gọi registry  
* lấy module tương ứng

---

## **8.5 ImportPipeline**

Đây là “trái tim” của package.

Nhiệm vụ:

* điều phối toàn bộ flow import

Pseudo flow:

resolve module  
resolve session/source  
read headers  
check required headers  
for each row:  
 parse  
 skip blank  
 validate  
 map  
 if preview:  
     build preview row  
 if commit:  
     commit row  
aggregate summary  
return result

Pipeline **không chứa business rule cụ thể**.

---

## **8.6 ImportPreviewService**

Wrapper cho use case preview.

Nhiệm vụ:

* nhận request DTO  
* gọi pipeline ở mode preview  
* trả `PreviewResult`

---

## **8.7 ImportCommitService**

Wrapper cho use case submit.

Nhiệm vụ:

* check policy  
* tạo `ImportJob`  
* dispatch queue  
* trả `job_id`

---

## **8.8 ImportJobStatusService**

Dùng để query trạng thái import job.

Nhiệm vụ:

* đọc trạng thái từ DB/repository  
* chuẩn hóa response status

---

# **9\. Dữ liệu cần lưu trong DB**

Package nên chuẩn hóa ít nhất 2 bảng.

---

## **9.1 Bảng `import_preview_sessions`**

Chứa trạng thái session preview.

Các cột đề xuất:

* `id`  
* `kind`  
* `file_handle`  
* `tenant_id`  
* `workspace_id`  
* `context` (json)  
* `status`  
* `expires_at`  
* `created_at`  
* `updated_at`

### **Tại sao cần bảng/session riêng?**

Vì preview là một vòng đời riêng:

* upload  
* preview nhiều lần  
* submit  
* expire

Không nên để FE phải upload lại file mỗi lần.

---

## **9.2 Bảng `import_jobs`**

Chứa trạng thái import thật.

Các cột đề xuất:

* `id`  
* `kind`  
* `session_id`  
* `status`  
* `submitted_by`  
* `total_rows`  
* `processed_rows`  
* `ok_rows`  
* `error_rows`  
* `skipped_blank_rows`  
* `checkpoint`  
* `summary`  
* `started_at`  
* `finished_at`

### **Ý nghĩa**

Bảng này là nguồn sự thật để:

* hiện progress  
* show error summary  
* retry / audit

---

# **10\. Chuẩn response preview mà mọi import phải dùng chung**

Đây là phần nên chốt cứng để FE và BE đồng bộ.

Ví dụ:

{  
 "session\_id": "uuid",  
 "kind": "cost\_center\_entry",  
 "summary": {  
   "total\_seen": 120,  
   "ok": 100,  
   "error": 15,  
   "skipped\_blank": 5  
 },  
 "pagination": {  
   "page": 1,  
   "per\_page": 20,  
   "filtered\_total": 15,  
   "next\_cursor": null  
 },  
 "rows": \[  
   {  
     "line": 2,  
     "status": "error",  
     "errors": \[  
       {  
         "field": "amount",  
         "code": "INVALID\_NUMBER",  
         "message": "Amount must be numeric"  
       }  
     \],  
     "normalized": {  
       "costcenter\_code": "CC01",  
       "category\_code": "CAT1",  
       "amount": "abc"  
     },  
     "preview": null  
   }  
 \]  
}  
---

## **Giải thích từng phần**

### **`summary`**

* `total_seen`: tổng số dòng đã xét  
* `ok`: số dòng hợp lệ  
* `error`: số dòng lỗi  
* `skipped_blank`: số dòng trống bị bỏ qua

### **`rows`**

Mỗi dòng preview phải có:

* `line`  
* `status`  
* `errors[]`  
* `normalized`  
* `preview` optional

### **`errors[]`**

Nên luôn là mảng object:

* `field`  
* `code`  
* `message`

Điều này giúp FE:

* highlight đúng field  
* dịch/code lỗi tốt hơn  
* thống nhất giữa các loại import

---

# **11\. Vì sao phải có `context` trong session?**

Có nhiều loại import không chỉ phụ thuộc file.

Ví dụ:

* import cost center theo `period`  
* import merchant balance theo `workspace_id`  
* import payroll theo `month`

Nếu chỉ lưu file mà không lưu `context`, khi preview/commit lại:

* có thể dùng nhầm kỳ  
* có thể dùng nhầm tenant/workspace  
* logic validate không xác định

Nên `session` phải lưu context tối thiểu.

---

# **12\. Policy và security**

Đây là phần đội dev hay bỏ sót.

## **12.1 Permission theo kind**

Không phải ai cũng được import mọi loại dữ liệu.

Nên có policy kiểu:

* `imports.cost_center_entry.preview`  
* `imports.cost_center_entry.commit`

Controller chỉ làm:

* check user có quyền preview/commit kind này không

---

## **12.2 Tenant / workspace isolation**

Khi lấy `session_id`, phải luôn so:

* session có thuộc tenant hiện tại không  
* workspace có match không

Không được chỉ `find(session_id)` rồi dùng luôn.

---

## **12.3 Không expose đường dẫn file**

Client chỉ nên biết:

* `session_id`  
* `job_id`

Không nên biết:

* absolute path  
* disk path  
* storage key nội bộ

---

# **13\. Duplicate policy**

Không phải loại import nào cũng xử lý duplicate giống nhau.

Ví dụ:

* loại A: duplicate thì update  
* loại B: duplicate thì skip  
* loại C: duplicate thì fail

Vì vậy module nên tự định nghĩa `duplicate policy`.

Ví dụ:

* `UPDATE`  
* `SKIP`  
* `FAIL`

Phần này thuộc domain, không thuộc package core.

---

# **14\. Commit nên theo row hay theo chunk?**

Đây là điểm đội dev cần chốt theo quy mô dữ liệu.

## **14.1 Sync**

Dùng khi:

* file rất nhỏ  
* import admin nội bộ  
* cần kết quả ngay

## **14.2 Single queued job**

Dùng khi:

* file vừa  
* đủ lớn để không chạy đồng bộ  
* nhưng chưa cần chunk phức tạp

## **14.3 Chunked queued jobs**

Dùng khi:

* file lớn  
* cần retry theo chunk  
* cần tracking progress tốt hơn

### **Khuyến nghị**

Phiên bản đầu:

* preview sync  
* commit queue single job

Phiên bản sau:

* nâng cấp chunked commit nếu file lớn

Không nên làm quá phức tạp ngay từ đầu nếu chưa có nhu cầu thật.

---

# **15\. Logging và observability**

Đội dev cần có quy ước từ đầu.

## **Mỗi log nên có:**

* `session_id`  
* `job_id`  
* `kind`  
* `tenant_id`  
* `workspace_id`

## **Không nên:**

* log warning cho từng dòng lỗi trong preview

Vì:

* preview có thể bị gọi nhiều lần  
* log sẽ rất noisy

## **Nên:**

* log aggregate summary  
* log sample errors  
* log chi tiết hơn ở commit job nếu cần audit

---

# **16\. Quy trình “thêm loại import mới” sau khi package hoàn thành**

Đây là mục quan trọng nhất để giao team.

Giả sử muốn thêm `payroll_metric`.

Dev chỉ cần:

## **Bước 1**

Tạo module:

* `PayrollMetricImportModule`

## **Bước 2**

Tạo parser:

* `PayrollMetricRowParser`

## **Bước 3**

Tạo validator:

* `PayrollMetricRowValidator`

## **Bước 4**

Tạo mapper:

* `PayrollMetricRowMapper`

## **Bước 5**

Tạo committer:

* `PayrollMetricRowCommitter`

## **Bước 6**

Đăng ký module vào `ImportRegistry`

## **Bước 7**

Viết test cho parse/validate/commit

### **Điểm quan trọng**

Không phải sửa:

* pipeline  
* controller chung  
* flow preview  
* flow submit  
* tracking job

Đây chính là lợi ích lớn nhất của kiến trúc.

---

# **17\. Những điểm đội dev cần tránh**

## **Sai lầm 1**

Nhét validate business trực tiếp vào controller

## **Sai lầm 2**

Preview và commit dùng hai bộ rule khác nhau

## **Sai lầm 3**

Module import gọi trực tiếp reader Excel  
 Domain không nên biết Excel/CSV

## **Sai lầm 4**

Package core chứa business logic của từng kind  
 Package chỉ nên chứa framework

## **Sai lầm 5**

Không chuẩn hóa response envelope  
 Sau này FE sẽ rất khổ

## **Sai lầm 6**

Thiết kế package “bao mọi use case”  
 Nên target 80% import dạng tabular, row-based

---

# **18\. Cấu trúc thư mục gợi ý**

## **Package `import-kit`**

src/  
 Contracts/  
   ImportModuleInterface.php  
   RowParserInterface.php  
   RowValidatorInterface.php  
   RowMapperInterface.php  
   RowCommitterInterface.php  
   FileStoreInterface.php  
   PreviewSessionStoreInterface.php  
   SourceReaderInterface.php  
   ImportRegistryInterface.php

 DTO/  
   ImportRunContext.php  
   PreviewResult.php  
   PreviewRowResult.php  
   ValidationResult.php  
   CommitResult.php  
   StoredFile.php  
   PreviewSession.php  
   ImportJobData.php

 Pipeline/  
   ImportPipeline.php

 Services/  
   ImportPreviewService.php  
   ImportCommitService.php  
   ImportJobStatusService.php

 Repositories/  
   ImportJobRepository.php  
   PreviewSessionRepository.php

 Jobs/  
   RunImportJob.php

 Support/  
   ImportMode.php  
   DuplicatePolicy.php  
   HeaderMap.php  
   RowWindow.php  
---

## **Trong từng service**

app/  
 Imports/  
   CostCenterEntry/  
     CostCenterEntryImportModule.php  
     CostCenterEntryRowParser.php  
     CostCenterEntryRowValidator.php  
     CostCenterEntryRowMapper.php  
     CostCenterEntryRowCommitter.php  
---

# **19\. Gợi ý rollout để đội dev làm ít rủi ro**

Mình khuyên triển khai theo 3 phase.

## **Phase 1 — dựng khung tối thiểu**

Làm:

* `ImportModuleInterface`  
* `ImportRegistry`  
* `FileStore`  
* `PreviewSessionStore`  
* `SourceReader`  
* `ImportPipeline`  
* `ImportPreviewService`  
* `ImportCommitService`  
* `ImportJob` cơ bản  
* 1 import mẫu: `cost_center_entry`

### **Mục tiêu**

Chứng minh flow chạy end-to-end.

---

## **Phase 2 — chuẩn hóa và test**

Làm thêm:

* response envelope chuẩn  
* error code chuẩn  
* integration tests  
* cleanup expired session/file  
* permission hooks  
* better job status

---

## **Phase 3 — scale**

Chỉ làm khi cần:

* chunked jobs  
* checkpoint/retry  
* metrics  
* notifications  
* dashboard import

---

# **20\. Bản chốt ngắn gọn để bạn nói với dev team**

Bạn có thể nói ngắn gọn như sau:

Chúng ta sẽ làm một package import dùng chung.  
 Package này chỉ xử lý flow chung: upload file, tạo session, preview, submit queue, tracking job.  
 Mỗi loại import chỉ cần cắm một module riêng gồm parser, validator, mapper, committer.  
 Preview và commit dùng chung pipeline để tránh lệch rule.  
 Từ nay thêm import mới không được copy flow cũ, mà phải đăng ký một module mới vào registry.

---

# **21\. Recommendation triển khai thực tế**

Nếu giao đội dev, mình đề nghị chốt các quyết định này ngay:

## **Quyết định 1**

Dùng tên abstraction là `ImportModule`

## **Quyết định 2**

Tách 4 step:

* parser  
* validator  
* mapper  
* committer

## **Quyết định 3**

Preview và commit dùng cùng pipeline

## **Quyết định 4**

Chuẩn hóa response preview/job status từ đầu

## **Quyết định 5**

Làm 1 import reference trước, không migrate tất cả import cùng lúc

## **Quyết định 6**

V1 chỉ cần single queued job, chưa cần chunk nếu chưa có pain thật

