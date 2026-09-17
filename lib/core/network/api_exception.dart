class ApiException implements Exception {
  final String message;
  final int? statusCode;
  final dynamic data;

  const ApiException(
    this.message, {
    this.statusCode,
    this.data,
  });

  @override
  String toString() =>
      'ApiException(statusCode: $statusCode, message: $message)';
}

class ApiTimeoutException extends ApiException {
  const ApiTimeoutException() : super('Request timed out');
}

class ApiNetworkException extends ApiException {
  const ApiNetworkException() : super('Network request failed');
}
