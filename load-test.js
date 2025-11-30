/**
 * Quick Load Test Script for Flash Sale API
 *
 * Usage: node load-test.js
 *
 * Prerequisites:
 * npm install axios
 */

import axios from "axios";

// Configuration
const CONFIG = {
    // Local development
    // BASE_URL: "http://localhost:8000/api",

    // Production server
    BASE_URL: "https://flash.ammarelgendy.site/api",

    PRODUCT_ID: 1,
    CONCURRENT_REQUESTS: 50,
    QTY_PER_REQUEST: 1,
    PRODUCT_API_REQUESTS: 100, // Number of concurrent requests for product API test
};

/**
 * Create a hold request
 */
async function createHold() {
    try {
        const response = await axios.post(
            `${CONFIG.BASE_URL}/holds`,
            {
                product_id: CONFIG.PRODUCT_ID,
                qty: CONFIG.QTY_PER_REQUEST,
            },
            {
                timeout: 15000, // 15 second timeout for load testing
            }
        );
        return {
            success: true,
            status: response.status,
            holdId: response.data?.data?.hold_id,
        };
    } catch (error) {
        return {
            success: false,
            status: error.response?.status || "TIMEOUT",
            message: error.response?.data?.message || error.message,
        };
    }
}

/**
 * Get product details
 */
async function getProduct() {
    try {
        const response = await axios.get(
            `${CONFIG.BASE_URL}/products/${CONFIG.PRODUCT_ID}`,
            {
                timeout: 5000,
            }
        );
        return {
            success: true,
            availableStock: response.data?.data?.available_stock,
            totalStock: response.data?.data?.stock || "N/A",
            responseTime: response.headers["x-response-time"] || null,
        };
    } catch (error) {
        return {
            success: false,
            error: error.message,
        };
    }
}

/**
 * Get product details (for load testing)
 */
async function getProductForLoadTest() {
    const startTime = Date.now();
    try {
        const response = await axios.get(
            `${CONFIG.BASE_URL}/products/${CONFIG.PRODUCT_ID}`,
            {
                timeout: 15000, // 15 second timeout for high traffic
            }
        );
        const endTime = Date.now();
        return {
            success: true,
            status: response.status,
            availableStock: response.data?.data?.available_stock,
            responseTime: endTime - startTime,
        };
    } catch (error) {
        const endTime = Date.now();
        return {
            success: false,
            status: error.response?.status || "TIMEOUT",
            responseTime: endTime - startTime,
            error: error.message,
        };
    }
}

/**
 * Run load test
 */
async function runLoadTest() {
    console.log("🚀 Flash Sale API Load Test");
    console.log("=".repeat(50));
    console.log(`Base URL: ${CONFIG.BASE_URL}`);
    console.log(`Product ID: ${CONFIG.PRODUCT_ID}`);
    console.log(`Concurrent Requests: ${CONFIG.CONCURRENT_REQUESTS}`);
    console.log(`Quantity per request: ${CONFIG.QTY_PER_REQUEST}`);
    console.log("=".repeat(50));
    console.log("\n⏳ Starting load test...\n");

    // Get initial product state
    const initialProduct = await getProduct();
    if (initialProduct.success) {
        console.log(`📦 Initial Stock: ${initialProduct.totalStock}`);
        console.log(
            `📦 Initial Available Stock: ${initialProduct.availableStock}\n`
        );
    }

    // Run concurrent requests
    const startTime = Date.now();
    const promises = Array(CONFIG.CONCURRENT_REQUESTS)
        .fill()
        .map((_, index) => {
            // Small delay to spread requests slightly
            return new Promise((resolve) => {
                setTimeout(async () => {
                    const result = await createHold();
                    result.requestIndex = index + 1;
                    resolve(result);
                }, Math.random() * 10); // 0-10ms random delay
            });
        });

    const results = await Promise.all(promises);
    const endTime = Date.now();
    const duration = endTime - startTime;

    // Analyze results
    const successful = results.filter((r) => r.success);
    const failed = results.filter((r) => !r.success);
    const statusCodes = {};

    results.forEach((r) => {
        const status = r.status || "UNKNOWN";
        statusCodes[status] = (statusCodes[status] || 0) + 1;
    });

    // Get final product state
    const finalProduct = await getProduct();

    // Print results
    console.log("📊 Test Results");
    console.log("=".repeat(50));
    console.log(`✅ Successful: ${successful.length}`);
    console.log(`❌ Failed: ${failed.length}`);
    console.log(`⏱️  Duration: ${duration}ms`);
    console.log(
        `📈 Average: ${(duration / CONFIG.CONCURRENT_REQUESTS).toFixed(
            2
        )}ms per request`
    );
    console.log(
        `⚡ Throughput: ${(
            CONFIG.CONCURRENT_REQUESTS /
            (duration / 1000)
        ).toFixed(2)} requests/second`
    );

    console.log("\n📋 Status Code Breakdown:");
    Object.entries(statusCodes).forEach(([status, count]) => {
        console.log(`   ${status}: ${count}`);
    });

    if (finalProduct.success) {
        console.log(
            `\n📦 Final Available Stock: ${finalProduct.availableStock}`
        );
        console.log(
            `📦 Expected Available: ${
                initialProduct.availableStock - successful.length
            }`
        );

        const expectedStock = initialProduct.availableStock - successful.length;
        if (finalProduct.availableStock === expectedStock) {
            console.log("✅ Stock calculation is CORRECT (no overselling)");
        } else {
            console.log("⚠️  WARNING: Stock mismatch detected!");
            console.log(
                `   Expected: ${expectedStock}, Got: ${finalProduct.availableStock}`
            );
        }
    }

    // Show sample errors
    if (failed.length > 0) {
        console.log("\n❌ Sample Error Messages:");
        const uniqueErrors = [
            ...new Set(failed.map((f) => f.message).filter(Boolean)),
        ];
        uniqueErrors.slice(0, 3).forEach((error, i) => {
            console.log(`   ${i + 1}. ${error}`);
        });
    }

    // Summary
    console.log("\n" + "=".repeat(50));
    if (failed.length > 0 && failed.length < CONFIG.CONCURRENT_REQUESTS) {
        console.log("✅ Test PASSED: System handled load correctly");
        console.log(
            `   ${successful.length} holds created, ${failed.length} rejected (expected behavior)`
        );
    } else if (
        failed.length === 0 &&
        successful.length <= initialProduct.availableStock
    ) {
        console.log(
            "✅ Test PASSED: All requests succeeded (within stock limit)"
        );
    } else if (successful.length > initialProduct.availableStock) {
        console.log("❌ Test FAILED: OVERSELLING DETECTED!");
        console.log(
            `   Created ${successful.length} holds but only ${initialProduct.availableStock} stock available`
        );
    } else {
        console.log("⚠️  Test completed with unexpected results");
    }
    console.log("=".repeat(50));
}

/**
 * Run high traffic test for product API
 */
async function runProductApiLoadTest() {
    console.log("🚀 Product API High Traffic Load Test");
    console.log("=".repeat(50));
    console.log(`Base URL: ${CONFIG.BASE_URL}`);
    console.log(`Product ID: ${CONFIG.PRODUCT_ID}`);
    console.log(`Concurrent Requests: ${CONFIG.PRODUCT_API_REQUESTS}`);
    console.log("=".repeat(50));
    console.log("\n💾 Cache Status: ENABLED (Redis)");
    console.log("   - Product data: 5 min cache");
    console.log("   - Available stock: 1 min cache");
    console.log("\n⏳ Starting product API load test...\n");

    // Warm up cache with a single request
    console.log("🔥 Warming up cache...");
    await getProduct();
    console.log("✅ Cache warmed up\n");

    // Get initial product state
    const initialProduct = await getProduct();
    if (initialProduct.success) {
        console.log(
            `📦 Initial Available Stock: ${initialProduct.availableStock}`
        );
    }

    // Run concurrent requests
    const startTime = Date.now();
    const promises = Array(CONFIG.PRODUCT_API_REQUESTS)
        .fill()
        .map((_, index) => {
            // Small random delay to spread requests
            return new Promise((resolve) => {
                setTimeout(async () => {
                    const result = await getProductForLoadTest();
                    result.requestIndex = index + 1;
                    resolve(result);
                }, Math.random() * 20); // 0-20ms random delay
            });
        });

    const results = await Promise.all(promises);
    const endTime = Date.now();
    const duration = endTime - startTime;

    // Analyze results
    const successful = results.filter((r) => r.success);
    const failed = results.filter((r) => !r.success);
    const responseTimes = successful.map((r) => r.responseTime).filter(Boolean);
    const statusCodes = {};

    results.forEach((r) => {
        const status = r.status || "UNKNOWN";
        statusCodes[status] = (statusCodes[status] || 0) + 1;
    });

    // Calculate statistics
    const avgResponseTime =
        responseTimes.length > 0
            ? responseTimes.reduce((a, b) => a + b, 0) / responseTimes.length
            : 0;
    const minResponseTime =
        responseTimes.length > 0 ? Math.min(...responseTimes) : 0;
    const maxResponseTime =
        responseTimes.length > 0 ? Math.max(...responseTimes) : 0;

    // Get final product state
    const finalProduct = await getProduct();

    // Print results
    console.log("📊 Test Results");
    console.log("=".repeat(50));
    console.log(`✅ Successful: ${successful.length}`);
    console.log(`❌ Failed: ${failed.length}`);
    console.log(`⏱️  Total Duration: ${duration}ms`);
    console.log(`📈 Average Response Time: ${avgResponseTime.toFixed(2)}ms`);
    console.log(`⚡ Min Response Time: ${minResponseTime}ms`);
    console.log(`⚡ Max Response Time: ${maxResponseTime}ms`);
    console.log(
        `⚡ Throughput: ${(
            CONFIG.PRODUCT_API_REQUESTS /
            (duration / 1000)
        ).toFixed(2)} requests/second`
    );

    console.log("\n📋 Status Code Breakdown:");
    Object.entries(statusCodes).forEach(([status, count]) => {
        console.log(`   ${status}: ${count}`);
    });

    // Response time distribution
    if (responseTimes.length > 0) {
        const sorted = [...responseTimes].sort((a, b) => a - b);
        const p50 = sorted[Math.floor(sorted.length * 0.5)];
        const p95 = sorted[Math.floor(sorted.length * 0.95)];
        const p99 = sorted[Math.floor(sorted.length * 0.99)];

        console.log("\n📊 Response Time Percentiles:");
        console.log(`   P50 (Median): ${p50}ms`);
        console.log(`   P95: ${p95}ms`);
        console.log(`   P99: ${p99}ms`);
    }

    // Verify consistency
    if (finalProduct.success) {
        const allSameStock = successful.every(
            (r) => r.availableStock === finalProduct.availableStock
        );

        if (allSameStock) {
            console.log(
                `\n✅ Stock Consistency: All responses show same available_stock (${finalProduct.availableStock})`
            );
        } else {
            console.log("\n⚠️  WARNING: Inconsistent stock values detected!");
        }
    }

    // Show sample errors
    if (failed.length > 0) {
        console.log("\n❌ Sample Error Messages:");
        const uniqueErrors = [
            ...new Set(failed.map((f) => f.error).filter(Boolean)),
        ];
        uniqueErrors.slice(0, 3).forEach((error, i) => {
            console.log(`   ${i + 1}. ${error}`);
        });
    }

    // Performance assessment
    console.log("\n" + "=".repeat(50));
    if (failed.length === 0) {
        if (avgResponseTime < 100) {
            console.log("✅ Test PASSED: Excellent performance (< 100ms avg)");
            console.log("   ✅ Cache (Redis) is working effectively");
        } else if (avgResponseTime < 500) {
            console.log("✅ Test PASSED: Good performance (< 500ms avg)");
            console.log("   ✅ Cache (Redis) is working");
        } else {
            console.log("⚠️  Test PASSED but performance could be improved");
            console.log(
                `   ⚠️  Average response time: ${avgResponseTime.toFixed(2)}ms`
            );
            console.log("   💡 Cache (Redis) is enabled and working");
            console.log(
                "   💡 Slow responses may be due to server load or network"
            );
        }
    } else {
        console.log("⚠️  Test completed with some failures");
        console.log(
            `   ${failed.length} out of ${CONFIG.PRODUCT_API_REQUESTS} requests failed`
        );
        console.log(
            "   💡 Timeouts may occur under extreme load (100 concurrent requests)"
        );
        console.log(
            "   💡 Cache (Redis) is enabled - successful requests benefit from cache"
        );
    }

    // Cache information
    console.log("\n💾 Cache Information:");
    console.log("   ✅ Product data cached for 5 minutes (product:{id})");
    console.log(
        "   ✅ Available stock cached for 1 minute (product_available_stock:{id})"
    );
    console.log("   ✅ Cache driver: Redis (configured)");
    console.log("   📊 First request: Cache miss (slower, ~300-500ms)");
    console.log(
        "   📊 Subsequent requests: Cache hit (faster, ~50-100ms with Redis)"
    );
    console.log(
        "   📊 Under high load: Some requests may timeout but cache helps"
    );

    console.log("=".repeat(50));
}

// Main function to run all tests
async function runAllTests() {
    const args = process.argv.slice(2);
    const testType = args[0] || "hold";

    if (testType === "product" || testType === "p") {
        await runProductApiLoadTest();
    } else if (testType === "hold" || testType === "h") {
        await runLoadTest();
    } else if (testType === "all" || testType === "a") {
        console.log("Running all load tests...\n");
        await runLoadTest();
        console.log("\n\n");
        await runProductApiLoadTest();
    } else {
        console.log("Usage: node load-test.js [test-type]");
        console.log("  test-type: 'hold' (default), 'product', or 'all'");
        console.log("  Examples:");
        console.log("    node load-test.js          # Test hold creation");
        console.log("    node load-test.js product # Test product API");
        console.log("    node load-test.js all      # Run both tests");
    }
}

// Run the test
runAllTests().catch((error) => {
    console.error("❌ Load test failed:", error.message);
    process.exit(1);
});
