public class Example {
    public static void main(String[] args) {
        String endpoint = System.getenv().getOrDefault("APP_STATS_URL", "http://localhost:8080");
        String apiKey = System.getenv("APP_STATS_API_KEY");

        AppStats stats = new AppStats(endpoint, "my-java-app", "1.0.0", apiKey);

        // Use trackSync() for short-lived CLI programs, or track() for GUI/servers.
        boolean ok = stats.trackSync("launch");
        System.out.println("Stats sent: " + ok);
    }
}
