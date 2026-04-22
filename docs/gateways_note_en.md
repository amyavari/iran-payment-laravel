# Gateway Notes

## Behpardakht

- This gateway needs `soap` extension to be installed and enabled.
- This gateway sends the callback as a `POST` request.
- You must verify the payment within **20 minutes** after a successful gateway payment; otherwise, the gateway will **automatically reverse** it.
- A verified payment can be **reversed within 3 hours**.
- This gateway does not support verification without a callback, so related methods will return a **failed payment response**.

# Sep

- This gateway sends the callback as a `POST` request.
- You must verify the payment within **30 minutes** after a successful gateway payment; otherwise, the gateway will **automatically reverse** it.
- A verified payment can be **reversed within 50 minutes**.
- This gateway does not support verification without a callback, so related methods will return a **failed payment response**.

# Zarinpal

- This gateway sends the callback as a `GET` request.
- A successful payment can be **reversed within 30 minutes**.

# IDPay

- In your admin panel, you can choose to receive callback via `POST` or `GET`.
- You must verify the payment within **10 minutes** after a successful gateway payment; otherwise, the gateway will **automatically reverse** it.
- A verified payment **can not be reversed**, so related method will return a **failed payment reversal**.

# Pep

- This gateway sends the callback as a `GET` request.
- You must verify the payment within **10 minutes** after a successful gateway payment; otherwise, the gateway will **automatically reverse** it.
- A verified payment can be **reversed within 25 minutes**.
