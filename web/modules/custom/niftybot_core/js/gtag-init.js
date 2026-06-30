/**
 * @file
 * Google Analytics (gtag.js) initialization for GrassRed.
 */
(function (drupalSettings) {
  'use strict';

  const measurementId = drupalSettings.niftybotCore?.googleAnalyticsId;
  if (!measurementId) {
    return;
  }

  window.dataLayer = window.dataLayer || [];
  function gtag() {
    window.dataLayer.push(arguments);
  }
  window.gtag = gtag;

  gtag('js', new Date());
  gtag('config', measurementId);
})(drupalSettings);
