# Postman API Testing Guide

This guide will help you test the Flash Sale API using Postman.

## Prerequisites

1. **Start the Laravel server:**

    ```bash
    php artisan serve
    ```

    The API will be available at: `http://localhost:8000`

2. **Run migrations and seed data:**

    ```bash
    php artisan migrate
    php artisan db:seed
    ```

    This creates a product with:

    - Name: "Flash Sale Product"
    - Price: 150.00
    - Stock: 100

3. **Start the queue worker (for hold expiration):**
    ```bash
    php artisan queue:work
    ```

## API Base URL

```
http://localhost:8000/api
```

## Endpoints

### 1. Get Product Details

**GET** `/api/products/{id}`

**Example Request:**

```
GET http://localhost:8000/api/products/1
```

**Expected Response (200 OK):**

```json
{
    "data": {
        "id": 1,
        "name": "Flash Sale Product",
        "price": 150.0,
        "available_stock": 100
    }
}
```

**Postman Setup:**

-   Method: `GET`
-   URL: `http://localhost:8000/api/products/1`
-   Headers: None required

---

### 2. Create Hold

**POST** `/api/holds`

**Request Body:**

```json
{
    "product_id": 1,
    "qty": 2
}
```

**Expected Response (201 Created):**

```json
{
    "data": {
        "hold_id": 1,
        "expires_at": "2025-11-29T15:30:00Z"
    }
}
```

**Error Response (422 Unprocessable Entity):**

```json
{
    "message": "Insufficient stock. Available: 5, Requested: 10"
}
```

**Postman Setup:**

-   Method: `POST`
-   URL: `http://localhost:8000/api/holds`
-   Headers:
    -   `Content-Type: application/json`
    -   `Accept: application/json`
-   Body (raw JSON):
    ```json
    {
        "product_id": 1,
        "qty": 2
    }
    ```

---

### 3. Create Order

**POST** `/api/orders`

**Request Body:**

```json
{
    "hold_id": 1
}
```

**Expected Response (201 Created):**

```json
{
    "data": {
        "order_id": 1,
        "status": "pending_payment"
    }
}
```

**Error Response (422 Unprocessable Entity):**

```json
{
    "message": "Invalid or expired hold"
}
```

**Postman Setup:**

-   Method: `POST`
-   URL: `http://localhost:8000/api/orders`
-   Headers:
    -   `Content-Type: application/json`
    -   `Accept: application/json`
-   Body (raw JSON):
    ```json
    {
        "hold_id": 1
    }
    ```

---

### 4. Payment Webhook

**POST** `/api/payments/webhook`

**Request Body:**

```json
{
    "idempotency_key": "webhook-key-123",
    "order_id": 1,
    "status": "success"
}
```

**Status Values:**

-   `success` - Payment successful
-   `failure` - Payment failed

**Expected Response (200 OK):**

```json
{
    "status": "processed",
    "order_id": 1,
    "order_status": "paid"
}
```

**Duplicate Webhook Response (200 OK):**

```json
{
    "status": "duplicate",
    "order_id": 1,
    "order_status": "paid",
    "processed_at": "2025-11-29T15:30:00Z"
}
```

**Postman Setup:**

-   Method: `POST`
-   URL: `http://localhost:8000/api/payments/webhook`
-   Headers:
    -   `Content-Type: application/json`
    -   `Accept: application/json`
    -   `X-Webhook-Signature: <signature>` (optional, if webhook secret is configured)
-   Body (raw JSON):
    ```json
    {
        "idempotency_key": "webhook-key-123",
        "order_id": 1,
        "status": "success"
    }
    ```

---

## Complete Flow Example

### Step 1: Check Product Availability

```
GET http://localhost:8000/api/products/1
```

**Response:** Check `available_stock` to see how many items are available.

### Step 2: Create a Hold

```
POST http://localhost:8000/api/products/1
Body: {
    "product_id": 1,
    "qty": 2
}
```

**Response:** Save the `hold_id` and note the `expires_at` time (2 minutes from now).

### Step 3: Create an Order

```
POST http://localhost:8000/api/orders
Body: {
    "hold_id": <hold_id from step 2>
}
```

**Response:** Save the `order_id` for the webhook.

### Step 4: Process Payment Webhook (Success)

```
POST http://localhost:8000/api/payments/webhook
Body: {
    "idempotency_key": "unique-key-123",
    "order_id": <order_id from step 3>,
    "status": "success"
}
```

**Response:** Order status changes to `paid`.

### Step 4 Alternative: Process Payment Webhook (Failure)

```
POST http://localhost:8000/api/payments/webhook
Body: {
    "idempotency_key": "unique-key-456",
    "order_id": <order_id from step 3>,
    "status": "failure"
}
```

**Response:** Order status changes to `cancelled`, stock is restored.

---

## Testing Scenarios

### Scenario 1: Successful Purchase Flow

1. Get product → Check available stock
2. Create hold → Get hold_id
3. Create order → Get order_id
4. Send success webhook → Order becomes paid

### Scenario 2: Insufficient Stock

1. Create multiple holds to exhaust stock
2. Try to create another hold → Should get 422 error

### Scenario 3: Expired Hold

1. Create hold
2. Wait 2+ minutes (or manually expire in database)
3. Try to create order → Should get 422 error

### Scenario 4: Webhook Idempotency

1. Create order
2. Send webhook with idempotency_key "test-123"
3. Send same webhook again with same idempotency_key
4. Second request should return `"status": "duplicate"` without changing order

### Scenario 5: Payment Failure

1. Create order
2. Send failure webhook
3. Order should be cancelled
4. Stock should be restored (check product availability)

---

## Postman Collection Setup

### Environment Variables

Create a Postman Environment with these variables:

| Variable          | Initial Value               | Current Value               |
| ----------------- | --------------------------- | --------------------------- |
| `base_url`        | `http://localhost:8000/api` | `http://localhost:8000/api` |
| `product_id`      | `1`                         | `1`                         |
| `hold_id`         |                             | (will be set dynamically)   |
| `order_id`        |                             | (will be set dynamically)   |
| `idempotency_key` | `test-key-{{$timestamp}}`   | (auto-generated)            |

### Pre-request Scripts

For dynamic idempotency keys, use this in Pre-request Script:

```javascript
pm.environment.set("idempotency_key", "test-key-" + Date.now());
```

### Tests Scripts

**For Hold Creation:**

```javascript
if (pm.response.code === 201) {
    var jsonData = pm.response.json();
    pm.environment.set("hold_id", jsonData.data.hold_id);
    pm.test("Hold created successfully", function () {
        pm.response.to.have.status(201);
        pm.expect(jsonData.data).to.have.property("hold_id");
        pm.expect(jsonData.data).to.have.property("expires_at");
    });
}
```

**For Order Creation:**

```javascript
if (pm.response.code === 201) {
    var jsonData = pm.response.json();
    pm.environment.set("order_id", jsonData.data.order_id);
    pm.test("Order created successfully", function () {
        pm.response.to.have.status(201);
        pm.expect(jsonData.data.status).to.eql("pending_payment");
    });
}
```

**For Webhook:**

```javascript
pm.test("Webhook processed", function () {
    pm.response.to.have.status(200);
    var jsonData = pm.response.json();
    pm.expect(jsonData).to.have.property("status");
});
```

---

## Troubleshooting

### Issue: "Product not found"

-   **Solution:** Make sure you've run `php artisan db:seed` to create the product
-   Check the product ID in the database: `php artisan tinker` → `Product::first()->id`

### Issue: "Insufficient stock"

-   **Solution:** Check current stock: `GET /api/products/1`
-   Reset stock in database if needed

### Issue: "Invalid or expired hold"

-   **Solution:** Holds expire after 2 minutes
-   Create a new hold and use it immediately

### Issue: Webhook returns 422

-   **Solution:** Check that all required fields are present:
    -   `idempotency_key` (string)
    -   `order_id` (integer)
    -   `status` (must be "success" or "failure")

### Issue: Hold not expiring

-   **Solution:** Make sure queue worker is running: `php artisan queue:work`
-   Or run scheduler: `php artisan schedule:work`

---

## Quick Test Commands

### Using cURL (Alternative to Postman)

**Get Product:**

```bash
curl http://localhost:8000/api/products/1
```

**Create Hold:**

```bash
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"product_id":1,"qty":2}'
```

**Create Order:**

```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"hold_id":1}'
```

**Send Webhook:**

```bash
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"idempotency_key":"test-123","order_id":1,"status":"success"}'
```

---

## Monitoring

### Check Logs

```bash
tail -f storage/logs/laravel.log
```

### Check Database

```bash
php artisan tinker
```

Then:

```php
Product::first();
Hold::where('status', 'active')->count();
Order::all();
WebhookLog::latest()->first();
```

---

## Next Steps

1. Import the Postman collection (see below)
2. Set up environment variables
3. Run through the complete flow
4. Test edge cases (concurrency, expiration, idempotency)
5. Monitor logs for debugging
