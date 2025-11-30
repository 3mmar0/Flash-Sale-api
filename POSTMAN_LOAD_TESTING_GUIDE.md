# Postman Load Testing & Concurrency Guide

This guide shows you how to test load balancing, concurrency, and high-traffic scenarios in Postman for the Flash Sale API.

## Table of Contents

1. [Method 1: Collection Runner (Recommended)](#method-1-collection-runner-recommended)
2. [Method 2: Postman CLI (Newman)](#method-2-postman-cli-newman)
3. [Method 3: Multiple Postman Instances](#method-3-multiple-postman-instances)
4. [Load Test Scenarios](#load-test-scenarios)
5. [Verifying Results](#verifying-results)

---

## Method 1: Collection Runner (Recommended)

### Step 1: Prepare Your Collection

1. **Open Postman** and import the `Flash-Sale-API.postman_collection.json`
2. **Create a Load Test Folder** in your collection:
    - Right-click collection → "Add Folder" → Name it "Load Tests"

### Step 2: Create Concurrent Hold Requests

1. **Duplicate the "Create Hold" request** multiple times:

    - Right-click "Create Hold" → "Duplicate"
    - Rename them: "Create Hold 1", "Create Hold 2", etc.
    - Or create 20-50 duplicates for a proper load test

2. **Set up Pre-request Script** (to avoid conflicts):

    ```javascript
    // Generate unique idempotency key for each request
    pm.environment.set("unique_id", Date.now() + Math.random());

    // Optional: Randomize quantity (1-3)
    pm.environment.set("random_qty", Math.floor(Math.random() * 3) + 1);
    ```

3. **Update Request Body** to use variables:
    ```json
    {
        "product_id": {{product_id}},
        "qty": {{random_qty}}
    }
    ```

### Step 3: Run Collection Runner

1. **Click "Run"** button (top right of collection)
2. **Configure Runner Settings:**

    - **Iterations**: `50` (number of times to run)
    - **Delay**: `0` (no delay between requests for true concurrency)
    - **Data File**: None (or use CSV for different products)
    - **Run Order**: "Sequential" or "Random"

3. **Advanced Options:**

    - ✅ **Save responses** (to analyze later)
    - ✅ **Stop on error** (uncheck for load testing)
    - ✅ **Run collection without using saved cookies**

4. **Click "Run Flash Sale API"**

### Step 4: Analyze Results

After running, you'll see:

-   **Total requests**: 50
-   **Passed/Failed**: Count of each
-   **Average response time**
-   **Min/Max response times**

**Key Metrics to Check:**

-   ✅ Exactly `stock` number of successful holds (e.g., if stock=10, only 10 should succeed)
-   ✅ No overselling (check database)
-   ✅ Response times under load
-   ✅ Error rate (should be ~80-90% if testing with stock=10 and 50 requests)

---

## Method 2: Postman CLI (Newman)

For more advanced load testing, use Newman (Postman's CLI tool).

### Step 1: Install Newman

```bash
npm install -g newman
```

### Step 2: Create Load Test Script

Create `load-test.js`:

```javascript
const newman = require("newman");
const async = require("async");

const collection = require("./Flash-Sale-API.postman_collection.json");
const environment = require("./POSTMAN_ENVIRONMENT.json");

// Number of concurrent instances
const concurrentRuns = 10;
const iterationsPerRun = 10;

async.times(
    concurrentRuns,
    (n, next) => {
        newman.run(
            {
                collection: collection,
                environment: environment,
                iterationCount: iterationsPerRun,
                delayRequest: 0, // No delay for true concurrency
                reporters: ["cli", "json"],
                reporter: {
                    json: {
                        export: `results-run-${n}.json`,
                    },
                },
            },
            (err, summary) => {
                if (err) {
                    console.error(`Run ${n} failed:`, err);
                } else {
                    console.log(`Run ${n} completed:`, summary.run.stats);
                }
                next(err, summary);
            }
        );
    },
    (err, results) => {
        if (err) {
            console.error("Load test failed:", err);
            process.exit(1);
        } else {
            console.log("All runs completed!");
            console.log("Total requests:", results.length * iterationsPerRun);
        }
    }
);
```

### Step 3: Run Load Test

```bash
node load-test.js
```

This runs 10 concurrent instances, each making 10 requests = **100 total concurrent requests**.

---

## Method 3: Multiple Postman Instances

### Step 1: Open Multiple Postman Windows

1. Open Postman
2. Duplicate the window (File → New Window, or `Ctrl+N`)
3. Open 5-10 windows total

### Step 2: Configure Each Window

1. **Set different environment variables** in each window:

    - Window 1: `product_id = 1`
    - Window 2: `product_id = 1`
    - etc.

2. **Create a request in each window:**
    - Method: `POST`
    - URL: `{{base_url}}/holds`
    - Body: `{"product_id": 1, "qty": 1}`

### Step 3: Send Simultaneously

1. **Click "Send" in all windows at the same time** (or as close as possible)
2. **Observe results** in each window

**Tip:** Use a script to automate this:

```javascript
// In Pre-request Script
pm.sendRequest(
    {
        url: pm.environment.get("base_url") + "/holds",
        method: "POST",
        header: { "Content-Type": "application/json" },
        body: {
            mode: "raw",
            raw: JSON.stringify({
                product_id: 1,
                qty: 1,
            }),
        },
    },
    function (err, res) {
        console.log(res.json());
    }
);
```

---

## Load Test Scenarios

### Scenario 1: Stock Boundary Test (Most Important)

**Goal:** Verify no overselling under concurrent load

**Setup:**

1. Reset product stock to `10`
2. Create 50-100 concurrent hold requests
3. Each request: `qty: 1`

**Expected Result:**

-   ✅ Exactly **10 successful** holds (201 status)
-   ✅ **40-90 failed** holds (422 status) with "Insufficient stock"
-   ✅ Database shows exactly 10 active holds
-   ✅ Available stock = 0

**Postman Collection Runner Settings:**

-   Iterations: `50`
-   Delay: `0ms`
-   Run Order: `Sequential` (or `Random`)

**Verify:**

```sql
-- Check in database
SELECT COUNT(*) FROM holds WHERE product_id = 1 AND status = 'active';
-- Should be exactly 10

SELECT available_stock FROM products WHERE id = 1;
-- Should be 0 (or use API: GET /api/products/1)
```

---

### Scenario 2: High Traffic Product Endpoint

**Goal:** Test caching and response times under load

**Setup:**

1. Create 100 GET requests to `/api/products/1`
2. Run concurrently

**Expected Result:**

-   ✅ All requests return 200 OK
-   ✅ Average response time < 100ms (with Redis cache)
-   ✅ All responses show same `available_stock`
-   ✅ No database deadlocks

**Postman Collection:**

-   Create 100 duplicates of "Get Product" request
-   Run with Collection Runner
-   Check response times in results

---

### Scenario 3: Mixed Workload

**Goal:** Simulate real flash sale traffic

**Setup:**

1. 70% GET requests (product checks)
2. 20% POST requests (hold creation)
3. 10% POST requests (order creation)

**Collection Structure:**

```
Load Test Collection
├── Get Product (70 requests)
├── Create Hold (20 requests)
└── Create Order (10 requests)
```

**Run with Collection Runner:**

-   Total iterations: 100
-   Random order
-   No delay

---

### Scenario 4: Webhook Idempotency Under Load

**Goal:** Test webhook handling with duplicate keys

**Setup:**

1. Create an order first
2. Send 20 webhooks with **same** `idempotency_key`
3. Send 20 webhooks with **different** `idempotency_key`

**Expected Result:**

-   ✅ First webhook with unique key: processes successfully
-   ✅ All duplicates with same key: return `"status": "duplicate"`
-   ✅ Order status changes only once
-   ✅ No duplicate processing

---

## Verifying Results

### 1. Check Postman Results

After running Collection Runner:

1. **View Summary:**

    - Total requests
    - Passed/Failed count
    - Average response time
    - Min/Max response times

2. **View Individual Responses:**

    - Click on any request to see response
    - Check status codes
    - Verify response bodies

3. **Export Results:**
    - Click "Export Results" → Save as JSON
    - Analyze with scripts or tools

### 2. Check Database

```sql
-- Count active holds
SELECT COUNT(*) as active_holds
FROM holds
WHERE product_id = 1 AND status = 'active';

-- Check product stock
SELECT id, name, stock
FROM products
WHERE id = 1;

-- Check orders created
SELECT COUNT(*) as total_orders
FROM orders;

-- Check webhook logs
SELECT status, COUNT(*)
FROM webhook_logs
GROUP BY status;
```

### 3. Check Laravel Logs

```bash
# View logs in real-time
tail -f storage/logs/laravel.log

# Search for deadlock retries
grep "deadlock" storage/logs/laravel.log

# Search for hold creations
grep "Hold created" storage/logs/laravel.log
```

### 4. Use API to Verify

```bash
# Check available stock
curl http://localhost:8000/api/products/1

# Should show correct available_stock
```

---

## Advanced: Using Postman's Built-in Load Testing

Postman has built-in load testing features (requires Postman Pro/Enterprise):

1. **Create a Monitor:**

    - Collection → "Monitors" tab
    - Click "Create Monitor"
    - Set frequency: Every 1 minute
    - Set iterations: 50

2. **Configure Load Test:**

    - Set concurrent requests
    - Set duration
    - Set ramp-up time

3. **View Results:**
    - Real-time dashboard
    - Response time graphs
    - Error rates
    - Success rates

---

## Quick Load Test Script

Create a simple Node.js script for quick testing:

```javascript
// quick-load-test.js
const axios = require("axios");

const BASE_URL = "http://localhost:8000/api";
const PRODUCT_ID = 1;
const CONCURRENT_REQUESTS = 50;

async function createHold() {
    try {
        const response = await axios.post(`${BASE_URL}/holds`, {
            product_id: PRODUCT_ID,
            qty: 1,
        });
        return { success: true, status: response.status };
    } catch (error) {
        return {
            success: false,
            status: error.response?.status,
            message: error.response?.data?.message,
        };
    }
}

async function runLoadTest() {
    console.log(
        `Starting load test: ${CONCURRENT_REQUESTS} concurrent requests...`
    );

    const startTime = Date.now();
    const promises = Array(CONCURRENT_REQUESTS)
        .fill()
        .map(() => createHold());
    const results = await Promise.all(promises);
    const endTime = Date.now();

    const successful = results.filter((r) => r.success).length;
    const failed = results.filter((r) => !r.success).length;

    console.log("\n=== Results ===");
    console.log(`Total requests: ${CONCURRENT_REQUESTS}`);
    console.log(`Successful: ${successful}`);
    console.log(`Failed: ${failed}`);
    console.log(`Duration: ${endTime - startTime}ms`);
    console.log(
        `Average: ${(endTime - startTime) / CONCURRENT_REQUESTS}ms per request`
    );

    // Verify with API
    const productResponse = await axios.get(
        `${BASE_URL}/products/${PRODUCT_ID}`
    );
    console.log(
        `\nAvailable stock: ${productResponse.data.data.available_stock}`
    );
}

runLoadTest();
```

**Run it:**

```bash
npm install axios
node quick-load-test.js
```

---

## Tips for Effective Load Testing

1. **Start Small:** Begin with 10-20 requests, then scale up
2. **Monitor Resources:** Watch CPU, memory, database connections
3. **Check Logs:** Look for deadlocks, errors, warnings
4. **Verify Data:** Always check database state after tests
5. **Test Incrementally:** Test one endpoint at a time first
6. **Use Realistic Data:** Match production-like scenarios
7. **Clean Up:** Reset database between test runs

---

## Expected Behavior Under Load

### ✅ Correct Behavior:

-   No overselling (exact stock count)
-   Fast response times (< 500ms)
-   Proper error messages (422 for insufficient stock)
-   No data corruption
-   Cache invalidation works correctly
-   Deadlock retries succeed

### ❌ Red Flags:

-   More holds created than stock available
-   Very slow responses (> 2 seconds)
-   Database deadlocks not handled
-   Cache showing stale data
-   Missing error messages

---

## Troubleshooting

### Issue: All requests succeed (overselling)

**Solution:** Check database locking is working. Verify `lockForUpdate()` is being used.

### Issue: Very slow responses

**Solution:**

-   Check if Redis cache is working
-   Check database connection pool
-   Monitor server resources

### Issue: Collection Runner not truly concurrent

**Solution:** Use Newman CLI or multiple Postman instances for true concurrency.

### Issue: Can't verify results

**Solution:**

-   Export Postman results
-   Check database directly
-   Use API to verify state

---

## Summary

**Best Method for Load Testing:**

1. **Quick tests:** Collection Runner (50-100 iterations)
2. **Serious load testing:** Newman CLI with concurrent runs
3. **Real-world simulation:** Multiple Postman instances

**Key Metrics to Monitor:**

-   ✅ No overselling (critical!)
-   ✅ Response times
-   ✅ Success/failure rates
-   ✅ Database consistency
-   ✅ Cache performance

Happy load testing! 🚀
