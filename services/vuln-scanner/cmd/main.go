// Smoke skeleton for the vuln-scanner worker (A7): wires the shared
// logger/config/rmq packages and consumes this service's job queue, logging
// each delivery. Real processing lands in Plan C.
package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"

	"github.com/magecode/shared/go/config"
	"github.com/magecode/shared/go/logger"
	"github.com/magecode/shared/go/rmq"
)

// serviceName is both the D-26 service identifier and the job queue name.
const serviceName = "vuln-scanner"

// defaultPrefetch follows D-76 (CES: 5, SIM: 1, AID/VUL: 3).
const defaultPrefetch = "3"

func main() {
	log := logger.New(serviceName)
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	cfg, err := config.Load(config.Spec{
		"RABBITMQ_URL": {Required: true},
		"RMQ_PREFETCH": {Type: config.Int, Default: defaultPrefetch},
	})
	if err != nil {
		log.Error("loading config", logger.Err(err))
		os.Exit(1)
	}

	consumer, err := rmq.NewConsumer(ctx, rmq.Config{
		URL:           cfg.String("RABBITMQ_URL"),
		PrefetchCount: cfg.Int("RMQ_PREFETCH"),
		Logger:        log,
	})
	if err != nil {
		log.Error("connecting to rabbitmq", logger.Err(err))
		os.Exit(1)
	}
	defer consumer.Close()

	log.Info("worker started", "data", map[string]any{"queue": serviceName})
	err = consumer.Consume(ctx, serviceName, func(_ context.Context, d rmq.Delivery) error {
		log.Info("received job", "trace_id", d.TraceID,
			"data", map[string]any{"bytes": len(d.Body), "retry_count": d.RetryCount})
		return nil
	})
	if err != nil {
		log.Error("consumer stopped", logger.Err(err))
		os.Exit(1)
	}
	log.Info("shutdown complete")
}
