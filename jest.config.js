/** @type {import('jest').Config} */
module.exports = {
    testEnvironment: 'jest-environment-jsdom',
    testMatch: [
        '<rootDir>/tests/js/**/*.test.js',
        '<rootDir>/tests/js/**/*.test.jsx',
    ],
    transform: {},   // plain JS files — no Babel needed for the deploy-progress tests
    collectCoverageFrom: [
        'js/**/*.js',
        '!js/jquery-cookie-1.4.1/**',
    ],
    coverageDirectory: '.jest-coverage',
    verbose: true,
};
