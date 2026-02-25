/**
 * Segmentflow Connect — Global Type Declarations
 *
 * Ambient types for the CDN SDK (window.segmentflow) and the WooCommerce
 * page data object (window.__sf_wc) injected by PHP.
 *
 * @package Segmentflow_Connect
 */

/** Segmentflow SDK global (loaded via CDN <script> tag). */
interface SegmentflowSDK {
  init(config: Record<string, unknown>): void;
  identify(params: {
    userId?: string;
    traits?: Record<string, unknown>;
    context?: Record<string, unknown>;
  }): void;
  track(params: { event: string; properties?: Record<string, unknown> }): void;
  page(params?: { name?: string; properties?: Record<string, unknown> }): void;
  flush(): void;
}

/** WooCommerce page data injected by class-segmentflow-wc-tracking.php. */
interface SfWcPageData {
  page: "product" | "cart" | "checkout" | "thankyou" | "shop" | "category" | "other";
  currency: string;
  product?: {
    id: number;
    name: string;
    price: string;
    sku: string;
    categories: string[];
    image_url: string;
    url: string;
    type: string;
  };
  cart?: {
    items: Array<{
      product_id: number;
      variation_id: number;
      name: string;
      quantity: number;
      price: string;
      sku: string;
      image_url: string;
      url: string;
    }>;
    total: string;
    subtotal: string;
    item_count: number;
    cart_hash: string;
  };
  order?: {
    id: number;
    number: string;
    total: string;
    subtotal: string;
    tax: string;
    shipping: string;
    discount: string;
    payment_method: string;
    currency: string;
    items: Array<{
      product_id: number;
      name: string;
      quantity: number;
      price: string;
      sku: string;
    }>;
    coupon_codes: string[];
    already_tracked: boolean;
  };
}

declare global {
  interface Window {
    segmentflow?: SegmentflowSDK;
    __sf_wc?: SfWcPageData;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- jQuery types are not available in this project.
    jQuery?: any;
  }
}

export {};
