# Flash Sale Checkout API

A high-concurrency Flash Sale Checkout System built with Laravel 12, designed to handle burst traffic while preventing overselling, managing temporary inventory holds, and processing idempotent payment webhooks.

## 🌐 Live Server

**Postman Collection:** https://documenter.getpostman.com/view/17857372/2sB3dLUrz4

**Production API:** https://flash.ammarelgendy.site/api

**Quick Test:**

```bash
# Test product endpoint
curl https://flash.ammarelgendy.site/api/products/1

# Test hold creation
curl -X POST https://flash.ammarelgendy.site/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 1}'
```

**Load Testing:**

```bash
# Update load-test.js BASE_URL to production URL
node load-test.js product  # Test product API
node load-test.js hold      # Test hold creation
```

---

## Overview

This API implements a flash sale system that:

-   Prevents overselling under extreme concurrency using row-level database locking
-   Manages temporary inventory holds (2-minute expiration)
-   Processes payment webhooks idempotently
-   Handles out-of-order webhook delivery
-   Automatically expires holds and restores stock availability

## Requirements

-   PHP 8.2+
-   Laravel 12
-   MySQL (InnoDB)
-   Redis (recommended) or any Laravel cache driver (database/file/memcached)
-   **Free Redis Hosting**: Use Upstash, Redis Cloud, or Railway (see `FREE_REDIS_HOSTING_GUIDE.md`)
-   **Local Redis**: Install locally or use Docker
-   **No Redis**: Use database cache (slower but works)
-   Predis package (included via composer)

## Installation

1. Clone the repository:

```bash
git clone https://github.com/3mmar0/Flash-Sale-api.git
cd Flash-Sale-api
```

2. Install dependencies:

```bash
composer install
```

3. Copy environment file:

```bash
cp .env.example .env
```

4. Generate application key:

```bash
php artisan key:generate
```

5. Configure your database in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flash_sale
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

6. Run migrations:

```bash
php artisan migrate
```

7. Seed the database:

```bash
php artisan db:seed
```

8. **Setup Redis (Recommended for Production):**

    **Using Docker (Easiest):**

    ```bash
    docker run -d -p 6379:6379 --name redis redis:alpine
    ```

    **Or using Laravel Sail:**

    ```bash
    php artisan sail:install
    # Select: mysql, redis
    ./vendor/bin/sail up -d
    ```

    **Configure in `.env`:**

    ```env
    CACHE_STORE=redis
    REDIS_CLIENT=predis
    REDIS_HOST=127.0.0.1
    REDIS_PORT=6379
    REDIS_PASSWORD=null
    REDIS_CACHE_DB=1
    ```

    **Clear config cache:**

    ```bash
    php artisan config:clear
    ```

    > **Note:** The system uses Predis (pure PHP Redis client) - no PHP extension needed!

9. Start the queue worker and scheduler:

**Option 1: Run both in one command (background):**

```bash
php artisan queue:work & php artisan schedule:work
```

**Option 2: Using composer script (recommended):**

```bash
composer run worker
```

**Option 3: Run separately (if needed):**

```bash
php artisan queue:work
# In another terminal:
php artisan schedule:work
```

**For production server (with nohup):**

```bash
nohup php artisan queue:work > /dev/null 2>&1 & nohup php artisan schedule:work > /dev/null 2>&1 &
```

**Or use the combined dev command (includes server):**

```bash
composer run dev
```

## API Endpoints

**Base URLs:**

-   **Local Development:** `http://localhost:8000/api`
-   **Production Server:** `https://flash.ammarelgendy.site/api`

### GET /api/products

Get a paginated list of all products with available stock.

**Example Request:**

```bash
# Local
curl http://localhost:8000/api/products

# Production
curl https://flash.ammarelgendy.site/api/products
```

**Query Parameters:**

-   `page` (optional): Page number for pagination (default: 1)

**Response:**

```json
{
    "data": [
        {
            "id": 1,
            "name": "Flash Sale Product",
            "price": 150.0,
            "available_stock": 42
        }
    ],
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 1,
        "per_page": 15,
        "to": 1,
        "total": 1
    }
}
```

### GET /api/products/{id}

Get product details with available stock.

**Response:**

```json
{
    "id": 1,
    "name": "Flash Sale Product",
    "price": 150.0,
    "available_stock": 42
}
```

### GET /api/holds

Get a paginated list of all holds with product and order information.

**Example Request:**

```bash
# Local
curl http://localhost:8000/api/holds

# Production
curl https://flash.ammarelgendy.site/api/holds
```

**Query Parameters:**

-   `page` (optional): Page number for pagination (default: 1)

**Response:**

```json
{
    "data": [
        {
            "hold_id": 123,
            "product_id": 1,
            "qty": 2,
            "status": "active",
            "expires_at": "2025-11-28T14:30:00Z",
            "product": {
                "id": 1,
                "name": "Flash Sale Product",
                "price": 150.0
            },
            "order": {
                "order_id": 567,
                "status": "pending_payment"
            },
            "created_at": "2025-11-28T14:28:00Z",
            "updated_at": "2025-11-28T14:28:00Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 1,
        "per_page": 15,
        "to": 1,
        "total": 1
    }
}
```

### POST /api/holds

Create a temporary hold on inventory.

**Example Request:**

```bash
# Local
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 1}'

# Production
curl -X POST https://flash.ammarelgendy.site/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 1}'
```

**Request Body:**

```json
{
    "product_id": 1,
    "qty": 2
}
```

**Response:**

```json
{
    "hold_id": 123,
    "expires_at": "2025-11-28T14:30:00Z"
}
```

**Errors:**

-   `422`: Insufficient stock available

### GET /api/orders

Get a paginated list of all orders with hold and product information.

**Example Request:**

```bash
# Local
curl http://localhost:8000/api/orders

# Production
curl https://flash.ammarelgendy.site/api/orders
```

**Query Parameters:**

-   `page` (optional): Page number for pagination (default: 1)

**Response:**

```json
{
    "data": [
        {
            "order_id": 567,
            "status": "pending_payment",
            "payment_reference": null,
            "hold_id": 123,
            "product": {
                "id": 1,
                "name": "Flash Sale Product",
                "price": 150.0
            },
            "qty": 2,
            "created_at": "2025-11-28T14:30:00Z",
            "updated_at": "2025-11-28T14:30:00Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 1,
        "per_page": 15,
        "to": 1,
        "total": 1
    }
}
```

### POST /api/orders

Create an order from a valid hold.

**Request Body:**

```json
{
    "hold_id": 123
}
```

**Response:**

```json
{
    "order_id": 567,
    "status": "pending_payment"
}
```

**Errors:**

-   `422`: Invalid or expired hold

### POST /api/payments/webhook

Process payment webhook (idempotent).

**Request Body:**

```json
{
    "idempotency_key": "A1B2C3",
    "order_id": 567,
    "status": "success"
}
```

**Response:** Always returns `200 OK`

**Status values:** `success` or `failure`

## Assumptions and Invariants

### Assumptions

1. **Hold Duration**: Holds expire after 2 minutes
2. **Stock Calculation**: Available stock = total stock - active holds quantity
3. **Hold Lifecycle**:
    - `active` → can be used for order creation
    - `used` → hold has been converted to an order
    - `expired` → hold expired and stock restored
4. **Order Status**:
    - `pending_payment` → order created, awaiting payment
    - `paid` → payment successful
    - `cancelled` → payment failed or order cancelled
5. **Webhook Delivery**: Webhooks may arrive multiple times or out of order

### Invariants Enforced

1. **No Overselling**: Stock cannot go negative. Row-level locking ensures concurrent requests don't oversell.
2. **Hold Uniqueness**: Each hold can only be used once for order creation.
3. **Hold Validity**: Orders can only be created from active, non-expired holds.
4. **Webhook Idempotency**: Same `idempotency_key` processed multiple times produces the same result.
5. **Stock Consistency**: Stock is always accurate, considering active holds.

## Concurrency Strategy

### Database Locking

-   Uses `SELECT ... FOR UPDATE` for row-level locking on product table
-   All stock modifications happen within database transactions
-   Prevents race conditions and ensures atomic operations

### Deadlock Handling

-   Implements retry logic with exponential backoff (50ms, 100ms, 200ms)
-   Maximum 3 retry attempts
-   Logs deadlock occurrences for monitoring

### Caching Strategy

-   **Recommended**: Redis for production (10-50x faster than database cache)
-   Product data cached for 5 minutes (`product:{id}`)
-   Available stock cached for 1 minute (`product_available_stock:{id}`)
-   Cache invalidated on:
    -   Hold creation
    -   Hold expiration
    -   Order creation
    -   Webhook processing (on failure)

**Cache Keys:**

-   `product:{id}` - Full product data
-   `product_available_stock:{id}` - Available stock calculation

**Performance:**

-   Redis cache: ~0.1-1ms per read
-   Database cache: ~5-10ms per read
-   **Redis is 10-50x faster** for high-concurrency flash sales

## Background Jobs

### ExpireHoldsJob

Runs every minute via Laravel scheduler to:

-   Find expired active holds
-   Mark them as expired
-   Restore stock availability
-   Invalidate cache

**To run manually:**

```bash
php artisan queue:work
```

**To run scheduler:**

```bash
php artisan schedule:work
```

## Load Testing with Postman

For detailed instructions on testing load balancing, concurrency, and high-traffic scenarios, see:

📖 **[POSTMAN_LOAD_TESTING_GUIDE.md](POSTMAN_LOAD_TESTING_GUIDE.md)**

**Quick Load Test:**

```bash
# Install dependencies
npm install axios

# Run load test script
node load-test.js
```

This will send 50 concurrent hold requests and verify:

-   ✅ No overselling occurs
-   ✅ Response times are acceptable
-   ✅ Stock calculations are correct

## Testing

### Run All Tests

```bash
composer test
# or
php artisan test
```

### Run Specific Test Suite

```bash
# Feature tests
php artisan test --testsuite=Feature

# Unit tests
php artisan test --testsuite=Unit
```

### Test Coverage

The test suite includes:

1. **Product Tests**: Product endpoint, caching
2. **Hold Tests**: Hold creation, expiration, stock reduction
3. **Order Tests**: Order creation, validation
4. **Webhook Tests**: Idempotency, success/failure handling, out-of-order delivery
5. **Concurrency Tests**: Parallel hold creation, stock boundary testing
6. **Unit Tests**: Service layer logic

### Critical Test Scenarios

1. **Parallel Hold Creation**: 100 concurrent requests for stock 10 → exactly 10 holds created
2. **Hold Expiration**: Create hold, expire it, verify stock restored
3. **Webhook Idempotency**: Send same webhook 5 times → order status changes only once
4. **Out-of-Order Webhook**: Send webhook before order creation → system handles gracefully
5. **Deadlock Handling**: System retries and succeeds under deadlock conditions

## Logging and Metrics

### Log Locations

-   Application logs: `storage/logs/laravel.log`
-   Structured logging includes:
    -   Hold creations and expirations
    -   Order creations
    -   Webhook events (processed/duplicate)
    -   Deadlock retries
    -   Cache invalidations

### Key Metrics to Monitor

1. **Hold Operations**:

    - Hold creation rate
    - Hold expiration rate
    - Failed hold creations (insufficient stock)

2. **Concurrency**:

    - Deadlock retry count
    - Concurrent request handling
    - Cache hit/miss rates

3. **Webhooks**:

    - Webhook processing rate
    - Duplicate webhook count
    - Out-of-order webhook handling

4. **Stock**:
    - Available stock accuracy
    - Stock restoration events

### Viewing Logs

```bash
# Real-time log viewing (if Laravel Pail is installed)
php artisan pail

# Or tail the log file
tail -f storage/logs/laravel.log
```

## Configuration

### Webhook Secret

Set in `.env`:

```env
WEBHOOK_SECRET=your_secret_key_here
```

The webhook endpoint validates signatures using this secret. If not set, validation is skipped (development mode).

### Cache Driver

**Recommended: Redis** (best performance for flash sales)

Configure in `.env`:

```env
# Production (Recommended)
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_CACHE_DB=1

# Development (Alternative)
CACHE_STORE=database
# or: file, memcached
```

**Redis Setup:**

1. **Start Redis (Docker):**

    ```bash
    docker run -d -p 6379:6379 --name redis redis:alpine
    ```

2. **Verify Redis is running:**

    ```bash
    docker exec redis redis-cli ping
    # Should return: PONG
    ```

3. **Test cache:**
    ```bash
    php artisan tinker
    >>> Cache::put('test', 'works', 60)
    >>> Cache::get('test')
    ```

**Note:** The system uses Predis (pure PHP Redis client) - no PHP Redis extension installation required!

## Architecture

### MVC Structure

-   **Models**: Product, Hold, Order, WebhookLog
-   **Controllers**: ProductController, HoldController, OrderController, PaymentWebhookController
-   **Services**: StockService, HoldService, OrderService, PaymentWebhookService
-   **Jobs**: ExpireHoldsJob
-   **Requests**: CreateHoldRequest, CreateOrderRequest, WebhookRequest
-   **Resources**: ProductResource, HoldResource, OrderResource
-   **Traits**: RetriesOnDeadlock (deadlock handling)
-   **Exceptions**: InsufficientStockException, InvalidHoldException

### Service Layer

Business logic is encapsulated in service classes:

-   **StockService**: Stock calculations and cache management
-   **HoldService**: Hold creation, expiration, validation (with deadlock retry)
-   **OrderService**: Order creation from holds
-   **PaymentWebhookService**: Idempotent webhook processing

## Performance Considerations

-   **Redis caching**: Product reads cached in Redis (10-50x faster than database)
-   Stock calculations use efficient queries with proper indexing
-   Hold expiration runs in background to avoid blocking requests
-   Database indexes on: `product_id`, `status`, `expires_at`, `idempotency_key`
-   Cache invalidation is instant with Redis (~0.1ms vs ~5ms with database)

**Performance Metrics:**

-   Product endpoint (cached): ~1-5ms with Redis
-   Hold creation: ~50-100ms (includes database transaction)
-   Cache hit rate: Should be >90% during flash sale

## Security

-   Webhook signature validation (configurable)
-   Input validation on all endpoints
-   SQL injection protection via Eloquent ORM
-   Transaction-based operations for data integrity

## Troubleshooting

### Holds Not Expiring

Ensure the scheduler is running:

```bash
php artisan schedule:work
```

Or check queue worker:

```bash
php artisan queue:work
```

### Cache Issues

Clear cache:

```bash
php artisan cache:clear
php artisan config:clear
```

**Redis Connection Issues:**

```bash
# Check Redis is running
docker ps | findstr redis
# Or
docker exec redis redis-cli ping

# Verify cache driver
php artisan tinker
>>> config('cache.default')
# Should return: "redis"
```

**Switch back to database cache (if needed):**

```env
CACHE_STORE=database
```

Then: `php artisan config:clear`

### Database Deadlocks

Check logs for deadlock retry messages. The system automatically retries with exponential backoff.

## Quick Start Checklist

-   [ ] Install dependencies: `composer install`
-   [ ] Configure database in `.env`
-   [ ] Run migrations: `php artisan migrate`
-   [ ] Seed database: `php artisan db:seed`
-   [ ] Setup Redis: `docker run -d -p 6379:6379 --name redis redis:alpine`
-   [ ] Configure Redis in `.env`: `CACHE_STORE=redis`
-   [ ] Clear cache: `php artisan config:clear`
-   [ ] Start queue worker: `php artisan queue:work`
-   [ ] Start scheduler: `php artisan schedule:work`
-   [ ] Test API: `curl http://localhost:8000/api/products/1`

## Additional Resources

-   **Postman Collection**: `Flash-Sale-API.postman_collection.json`
-   **Postman Guide**: `POSTMAN_GUIDE.md`
-   **Redis Setup**: `REDIS_SETUP_GUIDE.md` or `REDIS_WINDOWS_SETUP.md`
-   **Requirements Compliance**: `REQUIREMENTS_COMPLIANCE.md`

## License

MIT License
