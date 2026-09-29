/**
 * Simulates network latency so loading states remain visible during UI work.
 * Remove or shorten delays once real APIs are wired.
 *
 * @param {number} [ms=380]
 * @returns {Promise<void>}
 */
export function mockNetworkDelay(ms = 380) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}
