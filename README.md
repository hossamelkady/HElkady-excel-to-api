# HElkady-excel-to-api

Upload an Excel or CSV file (invoices, sales, stock, any accounting sheet) and get a JSON API for it: list rows, filter, search, sort, and sum a numeric column. Built with Laravel 12 and PhpSpreadsheet.

ارفع ملف Excel أو CSV (فواتير، مبيعات، مخزون، أي شيت محاسبي) واحصل على API بصيغة JSON: عرض الصفوف، فلترة، بحث، ترتيب، وجمع أي عمود رقمي.

> Status: v0.1. Feature tests are written but have not been run yet. Run `php artisan test` first.
> الحالة: إصدار تجريبي. التيستات مكتوبة ولسه ما اتشغلتش. شغّل `php artisan test` الأول.

## How it works

1. You upload a file. The first row is read as column headers.
2. Each column is typed as `number`, `date` or `text`. Excel date cells become `YYYY-MM-DD`.
3. Rows are stored in the database. Arabic headers and values are kept as they are.
4. You query the API using the original header names.

## Setup

```bash
git clone https://github.com/hossamelkady/HElkady-excel-to-api.git
cd HElkady-excel-to-api
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

Set a long random `API_KEY` in `.env`, then run `php artisan serve`. Every request must send it:

```
X-API-Key: your-key
```

If `API_KEY` is empty, the API refuses all requests with `503` and a message saying the key is not configured. Files with more columns than the limit are rejected with `422` instead of being truncated.

## Endpoints

| Method | Path | What it does |
| --- | --- | --- |
| POST | `/api/datasets` | Upload a file (`file`, optional `name`). `.xlsx`, `.xls`, `.csv`, up to 10 MB |
| GET | `/api/datasets` | List datasets |
| GET | `/api/datasets/{slug}` | Dataset info and columns |
| GET | `/api/datasets/{slug}/rows` | Rows, paginated |
| GET | `/api/datasets/{slug}/summary?column=Amount` | count, sum, avg, min, max of a numeric column |
| DELETE | `/api/datasets/{slug}` | Delete a dataset and its rows |

Row query options:

- `filter[Customer]=Sara` exact match on a column
- `search=text` text contained in any text column
- `sort=Amount` or `sort=-Amount` (descending)
- `per_page=50` (max 200) and `page=2`

## Example

```bash
curl -H "X-API-Key: $KEY" -F file=@sales.xlsx -F name="Sales 2026" http://localhost:8000/api/datasets

curl -H "X-API-Key: $KEY" "http://localhost:8000/api/datasets/sales-2026/rows?filter[Customer]=Sara&sort=-Amount"
```

```json
{
  "data": [{ "id": 2, "Invoice": "INV-2", "Customer": "Sara", "Amount": 200, "Date": "2026-01-06" }],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 50, "total": 1 }
}
```

## Notes and limits

- Only the first sheet is read. Formulas are read as their calculated values.
- Duplicate or empty headers are renamed (`Name (2)`, `Column 3`).
- Numeric-looking text with a leading zero (phone numbers, codes) stays text.
- Limits are in `config/excel_api.php` (50,000 rows and 100 columns per file by default).
- This is a simple single-key API. Put it behind HTTPS and do not expose it publicly with sensitive financial data without more access control.

## Tests

```bash
php artisan test
```

## License

MIT
