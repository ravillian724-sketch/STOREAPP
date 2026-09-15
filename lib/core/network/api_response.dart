class ApiResponse {
  final int statusCode;
  final dynamic data;
  final Map<String, String> headers;

  const ApiResponse({
    required this.statusCode,
    this.data,
    this.headers = const {},
  });

  bool get isSuccess =>
      statusCode >= 200 && statusCode < 300;
}
