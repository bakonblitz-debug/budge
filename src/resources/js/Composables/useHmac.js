/**
 * HMAC Request Signing Composable
 *
 * Signs state-changing requests (POST, PUT, DELETE) with HMAC-SHA256.
 * Uses a per-session derived key shared via Inertia props.
 *
 * Usage:
 *   import { useHmac } from '@/Composables/useHmac'
 *
 *   const { signedForm } = useHmac()
 *
 *   // Instead of: router.post('/import', formData)
 *   // Use:        signedForm.post('/import', formData)
 */
import { router, usePage } from '@inertiajs/vue3'

let cryptoKey = null

async function getKey() {
    if (cryptoKey) return cryptoKey

    const page = usePage()
    const keyHex = page.props?.hmac_key

    if (!keyHex) return null

    const keyBytes = new Uint8Array(
        keyHex.match(/.{1,2}/g).map((byte) => parseInt(byte, 16))
    )

    cryptoKey = await crypto.subtle.importKey(
        'raw',
        keyBytes,
        { name: 'HMAC', hash: 'SHA-256' },
        false,
        ['sign']
    )

    return cryptoKey
}

async function sign(method, path, body) {
    const key = await getKey()
    if (!key) return {}

    const timestamp = String(Date.now())
    const bodyString = typeof body === 'string' ? body : JSON.stringify(body || {})
    const payload = `${timestamp}.${method}.${path}.${bodyString}`

    const encoder = new TextEncoder()
    const signatureBuffer = await crypto.subtle.sign('HMAC', key, encoder.encode(payload))

    const signature = Array.from(new Uint8Array(signatureBuffer))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('')

    return {
        'X-HMAC-Signature': signature,
        'X-HMAC-Timestamp': timestamp,
    }
}

export function useHmac() {
    const signedForm = {
        async post(url, data = {}, options = {}) {
            const headers = await sign('POST', url.replace(/^\//, ''), data)
            return router.post(url, data, {
                ...options,
                headers: { ...options.headers, ...headers },
            })
        },

        async put(url, data = {}, options = {}) {
            const headers = await sign('PUT', url.replace(/^\//, ''), data)
            return router.put(url, data, {
                ...options,
                headers: { ...options.headers, ...headers },
            })
        },

        async patch(url, data = {}, options = {}) {
            const headers = await sign('PATCH', url.replace(/^\//, ''), data)
            return router.patch(url, data, {
                ...options,
                headers: { ...options.headers, ...headers },
            })
        },

        async delete(url, options = {}) {
            const headers = await sign('DELETE', url.replace(/^\//, ''), {})
            return router.delete(url, {
                ...options,
                headers: { ...options.headers, ...headers },
            })
        },
    }

    return { sign, signedForm }
}
