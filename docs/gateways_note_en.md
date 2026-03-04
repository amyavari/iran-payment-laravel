# Gateway Notes

## Behpardakht

- This gateway needs `soap` extension to be installed and enabled.
- This gateway sends the callback as a `POST` request.
- You must verify the payment within **20 minutes** after a successful gateway payment; otherwise, the gateway will **automatically reverse** it.
- If a verified payment is **not settled**, the gateway will **auto-settle it after 3 hours**.
- A verified payment can be **reversed within 3 hours**, only if it hasn't been settled.
- This gateway does not support verification without a callback, so related methods will return a **failed payment response**.

# Sep

- This gateway sends the callback as a `POST` request.
- You must verify the payment within **30 minutes** after a successful gateway payment; otherwise, the gateway will **automatically reverse** it.
- A verified payment can be **reversed within 50 minutes**.
- This gateway does not support verification without a callback, so related methods will return a **failed payment response**.
