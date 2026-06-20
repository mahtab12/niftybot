"""Application configuration loaded from environment variables."""

from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Trading service settings loaded from environment."""

    # Internal API authentication (Drupal <-> Python)
    api_key: str = ""

    # Groww broker credentials
    groww_api_key: str = ""
    groww_api_secret: str = ""
    groww_totp_secret: str = ""
    groww_auth_method: str = "api_key"  # "api_key" or "totp"

    # Database (shared MariaDB with Drupal)
    db_host: str = "db"
    db_port: int = 3306
    db_user: str = "db"
    db_password: str = "db"
    db_name: str = "db"

    # Redis
    redis_url: str = "redis://redis:6379/0"

    # Service
    log_level: str = "INFO"
    debug: bool = False

    @property
    def database_url(self) -> str:
        return (
            f"mysql+pymysql://{self.db_user}:{self.db_password}"
            f"@{self.db_host}:{self.db_port}/{self.db_name}"
        )

    model_config = {"env_file": ".env", "env_file_encoding": "utf-8"}


settings = Settings()
